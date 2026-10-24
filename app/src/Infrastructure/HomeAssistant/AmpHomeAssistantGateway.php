<?php

declare(strict_types=1);

namespace App\Infrastructure\HomeAssistant;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\Client\WebsocketConnector;
use Amp\Websocket\Client\WebsocketHandshake;
use App\Application\Port\HomeAssistant\AuthenticationFailedException;
use App\Application\Port\HomeAssistant\CommandFailedException;
use App\Application\Port\HomeAssistant\CommandTimeoutException;
use App\Application\Port\HomeAssistant\ConnectionLostException;
use App\Application\Port\HomeAssistant\HomeAssistantConfig;
use App\Application\Port\HomeAssistant\HomeAssistantException;
use App\Application\Port\HomeAssistant\HomeAssistantGatewayInterface;
use App\Application\Port\HomeAssistant\ServiceCallResult;
use App\Application\Port\HomeAssistant\SubscriptionInterface;
use Psr\Log\LoggerInterface;

use function Amp\async;
use function Amp\delay;
use function Amp\Websocket\Client\websocketConnector;

/**
 * Home Assistant WebSocket client on AMPHP v3 (fibers).
 *
 * Lifecycle: {@see connect()} opens and authenticates. A reader fiber dispatches answers to the pending
 * requests and events to the subscriptions; a ping fiber sends `ping` every pingInterval and drops the
 * connection when no `pong` comes back within pongTimeout. When the connection drops unexpectedly, pending
 * requests fail with {@see ConnectionLostException}, the "lost" hooks run, then a reconnection loop retries
 * with exponential backoff, re-authenticates, re-subscribes every live subscription (the fresh initial
 * states are reported as changes against the cached ones) and finally runs the "reconnected" hooks.
 */
final class AmpHomeAssistantGateway implements HomeAssistantGatewayInterface
{
    private readonly PendingRequests $requests;
    private readonly CompressedStateDecoder $decoder;
    private readonly WebsocketConnector $connector;

    private ?WebsocketConnection $connection = null;
    private ?DeferredCancellation $session = null;
    private DeferredCancellation $stopSignal;
    private bool $stopped = false;

    /** @var Future<mixed>|null */
    private ?Future $connecting = null;

    /** @var Future<mixed>|null */
    private ?Future $reconnecting = null;

    /** @var array<int, EntitySubscription> by spl_object_id */
    private array $subscriptions = [];

    /** @var array<int, EntitySubscription> by WebSocket message id of the live subscription */
    private array $subscriptionsByWsId = [];

    /** @var list<\Closure(): void> */
    private array $lostHooks = [];

    /** @var list<\Closure(): void> */
    private array $reconnectedHooks = [];

    public function __construct(
        private readonly ConnectionSettings $settings,
        private readonly LoggerInterface $logger,
        private readonly ReconnectBackoff $backoff = new ReconnectBackoff(),
        ?WebsocketConnector $connector = null,
    ) {
        $this->requests = new PendingRequests();
        $this->decoder = new CompressedStateDecoder();
        $this->connector = $connector ?? websocketConnector();
        $this->stopSignal = new DeferredCancellation();
    }

    public function connect(): void
    {
        if (null !== $this->connection) {
            return;
        }

        if ($this->stopped) {
            $this->stopped = false;
            $this->stopSignal = new DeferredCancellation();
        }

        if (null !== $this->reconnecting) {
            $this->reconnecting->await();

            return;
        }

        if (null === $this->connecting) {
            $this->connecting = async($this->establish(...));
        }

        $connecting = $this->connecting;
        try {
            $connecting->await();
        } finally {
            if ($this->connecting === $connecting) {
                $this->connecting = null;
            }
        }
    }

    public function disconnect(): void
    {
        $this->stopped = true;
        $this->stopSignal->cancel();

        $connection = $this->connection;
        $this->connection = null;
        $this->session?->cancel();
        $this->session = null;

        foreach ($this->subscriptions as $subscription) {
            $subscription->deactivate();
        }
        $this->subscriptions = [];
        $this->subscriptionsByWsId = [];

        $this->requests->failAll(new ConnectionLostException('Disconnected from Home Assistant.'));
        $connection?->close();
    }

    public function getConfig(): HomeAssistantConfig
    {
        return ResultMapper::config($this->request('get_config'));
    }

    public function getStates(): array
    {
        return ResultMapper::states($this->request('get_states'));
    }

    public function subscribeEntities(array $entityIds, \Closure $listener): SubscriptionInterface
    {
        if ([] === $entityIds) {
            // An empty entity_ids would subscribe to every entity: refuse instead.
            throw new \InvalidArgumentException('At least one entity id is required to subscribe.');
        }

        $subscription = new EntitySubscription($entityIds, $listener, $this->detach(...), $this->logger);
        $this->subscriptions[spl_object_id($subscription)] = $subscription;

        try {
            $this->startSubscription($subscription);
        } catch (\Throwable $e) {
            unset($this->subscriptions[spl_object_id($subscription)]);
            $subscription->deactivate();

            throw $e;
        }

        return $subscription;
    }

    public function callService(string $domain, string $service, array $serviceData = [], array $target = []): ServiceCallResult
    {
        $fields = ['domain' => $domain, 'service' => $service, 'return_response' => false];
        // Empty arrays would be encoded as JSON lists, which Home Assistant rejects: leave them out.
        if ([] !== $serviceData) {
            $fields['service_data'] = $serviceData;
        }
        if ([] !== $target) {
            $fields['target'] = $target;
        }

        return ResultMapper::serviceCall($this->request('call_service', $fields));
    }

    public function getServices(): array
    {
        return ResultMapper::services($this->request('get_services'));
    }

    public function listAreas(): array
    {
        return ResultMapper::areas($this->request('config/area_registry/list'));
    }

    public function listDevices(): array
    {
        return ResultMapper::devices($this->request('config/device_registry/list'));
    }

    public function listEntityRegistry(): array
    {
        return ResultMapper::entityRegistry($this->request('config/entity_registry/list'));
    }

    public function onConnectionLost(\Closure $hook): void
    {
        $this->lostHooks[] = $hook;
    }

    public function onReconnected(\Closure $hook): void
    {
        $this->reconnectedHooks[] = $hook;
    }

    /**
     * Opens, authenticates and starts the reader and keep-alive fibers.
     */
    private function establish(): void
    {
        $connection = $this->openAndAuthenticate();
        if ($this->stopped) {
            $connection->close();

            throw new ConnectionLostException('Disconnected from Home Assistant.');
        }

        $this->connection = $connection;
        $session = new DeferredCancellation();
        $this->session = $session;
        async($this->readLoop(...), $connection);
        async($this->pingLoop(...), $session->getCancellation());
    }

    /**
     * @throws AuthenticationFailedException
     * @throws ConnectionLostException
     */
    private function openAndAuthenticate(): WebsocketConnection
    {
        $this->logger->debug('Connecting to Home Assistant at {url}', ['url' => $this->settings->url]);

        try {
            $connection = $this->connector->connect(
                new WebsocketHandshake($this->settings->url),
                new TimeoutCancellation($this->settings->commandTimeout),
            );
        } catch (\Throwable $e) {
            throw new ConnectionLostException('Cannot connect to Home Assistant: '.$e->getMessage(), 0, $e);
        }

        try {
            $this->authenticate($connection);
        } catch (\Throwable $e) {
            $connection->close();

            throw $e instanceof HomeAssistantException ? $e : new ConnectionLostException('Handshake with Home Assistant failed: '.$e->getMessage(), 0, $e);
        }

        $this->logger->info('Connected to Home Assistant.');

        return $connection;
    }

    /**
     * @throws HomeAssistantException
     */
    private function authenticate(WebsocketConnection $connection): void
    {
        $hello = $this->receiveHandshakeMessage($connection);
        if ('auth_required' !== ($hello['type'] ?? null)) {
            throw new ConnectionLostException('Unexpected first message from Home Assistant.');
        }

        $connection->sendText(Payload::encode(['type' => 'auth', 'access_token' => $this->settings->token]));

        $answer = $this->receiveHandshakeMessage($connection);
        match ($answer['type'] ?? null) {
            'auth_ok' => null,
            'auth_invalid' => throw new AuthenticationFailedException(Payload::optionalString($answer, 'message') ?? 'Invalid access token.'),
            default => throw new ConnectionLostException('Unexpected answer to authentication.'),
        };
    }

    /**
     * @return array<string, mixed>
     *
     * @throws HomeAssistantException
     */
    private function receiveHandshakeMessage(WebsocketConnection $connection): array
    {
        try {
            $message = $connection->receive(new TimeoutCancellation($this->settings->commandTimeout));
        } catch (CancelledException $e) {
            throw new ConnectionLostException('Timed out waiting for Home Assistant during authentication.', 0, $e);
        }

        if (null === $message) {
            throw new ConnectionLostException('Connection closed during authentication.');
        }

        return Payload::decodeObject($message->buffer());
    }

    private function readLoop(WebsocketConnection $connection): void
    {
        try {
            while (null !== ($message = $connection->receive())) {
                try {
                    $this->handleMessage(Payload::decodeObject($message->buffer()));
                } catch (\Throwable $e) {
                    $this->logger->warning('Ignoring unprocessable Home Assistant message: {message}', ['message' => $e->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Home Assistant reader stopped: {message}', ['message' => $e->getMessage()]);
        }

        $this->handleClosed($connection);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handleMessage(array $message): void
    {
        $id = $message['id'] ?? null;
        if (!\is_int($id)) {
            return;
        }

        switch ($message['type'] ?? null) {
            case 'result':
                if (true === ($message['success'] ?? false)) {
                    $this->requests->resolve($id, $message['result'] ?? null);

                    break;
                }
                $error = Payload::optionalMap($message, 'error');
                $this->requests->reject($id, new CommandFailedException(
                    Payload::optionalString($error, 'code') ?? 'unknown',
                    Payload::optionalString($error, 'message') ?? 'Command failed.',
                ));
                break;
            case 'pong':
                $this->requests->resolve($id, null);
                break;
            case 'event':
                $this->handleEvent($id, Payload::map($message['event'] ?? null, 'event'));
                break;
            default:
                break;
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function handleEvent(int $wsId, array $event): void
    {
        $subscription = $this->subscriptionsByWsId[$wsId] ?? null;
        if (null === $subscription) {
            return;
        }

        $update = $this->decoder->decode($event, $subscription->cache, $subscription->awaitingInitial);
        $subscription->awaitingInitial = false;
        $subscription->cache = $update->cache;
        $subscription->dispatch($update->changes);
    }

    /**
     * Cleans up after a connection that ended; starts the reconnection loop unless stopped on purpose.
     */
    private function handleClosed(WebsocketConnection $connection): void
    {
        if ($this->connection !== $connection) {
            return; // stale connection, or already handled
        }

        $this->connection = null;
        $this->session?->cancel();
        $this->session = null;
        $this->subscriptionsByWsId = [];
        $this->requests->failAll(new ConnectionLostException('Connection to Home Assistant lost.'));

        if ($this->stopped || null !== $this->reconnecting) {
            return;
        }

        $this->logger->warning('Connection to Home Assistant lost.');
        $this->reconnecting = async($this->reconnect(...));
    }

    private function reconnect(): void
    {
        try {
            $this->runHooks($this->lostHooks, 'connection lost');

            $attempt = 0;
            while (!$this->stopped) {
                $delay = $this->backoff->delayForAttempt($attempt++);
                $this->logger->info('Reconnecting to Home Assistant in {delay}s (attempt {attempt}).', ['delay' => round($delay, 1), 'attempt' => $attempt]);

                try {
                    delay($delay, true, $this->stopSignal->getCancellation());
                } catch (CancelledException) {
                    return;
                }

                try {
                    $this->establish();
                    $this->resubscribeAll();
                } catch (HomeAssistantException $e) {
                    $this->logger->warning('Reconnection failed: {message}', ['message' => $e->getMessage()]);
                    $this->abandonConnection();

                    continue;
                }

                if (null === $this->connection) {
                    continue; // dropped again right after resubscribing
                }

                $this->logger->info('Reconnected to Home Assistant.');
                break;
            }
        } finally {
            $this->reconnecting = null;
        }

        if (!$this->stopped && null !== $this->connection) {
            $this->runHooks($this->reconnectedHooks, 'reconnected');
        }
    }

    private function abandonConnection(): void
    {
        $connection = $this->connection;
        if (null === $connection) {
            return;
        }
        $this->handleClosed($connection);
        $connection->close();
    }

    private function resubscribeAll(): void
    {
        foreach ($this->subscriptions as $subscription) {
            try {
                $this->startSubscription($subscription);
            } catch (CommandFailedException $e) {
                $this->logger->error('Home Assistant refused to restore a subscription: {message}', ['message' => $e->getMessage()]);
            }
        }
    }

    /**
     * @param list<\Closure(): void> $hooks
     */
    private function runHooks(array $hooks, string $name): void
    {
        foreach ($hooks as $hook) {
            try {
                $hook();
            } catch (\Throwable $e) {
                $this->logger->error('Hook "{hook}" failed: {message}', ['hook' => $name, 'message' => $e->getMessage(), 'exception' => $e]);
            }
        }
    }

    private function pingLoop(Cancellation $cancellation): void
    {
        try {
            while (true) {
                delay($this->settings->pingInterval, false, $cancellation);

                $connection = $this->connection;
                try {
                    $this->request('ping', [], $this->settings->pongTimeout);
                } catch (CommandTimeoutException) {
                    $this->logger->warning('No pong from Home Assistant within {timeout}s, dropping the connection.', ['timeout' => $this->settings->pongTimeout]);
                    if (null !== $connection) {
                        $this->handleClosed($connection);
                        $connection->close();
                    }

                    return;
                } catch (HomeAssistantException) {
                    return;
                }
            }
        } catch (CancelledException) {
            // connection ended
        }
    }

    private function startSubscription(EntitySubscription $subscription): void
    {
        $connection = $this->requireConnection();
        $id = $this->requests->nextId();
        $subscription->wsId = $id;
        $subscription->awaitingInitial = true;
        // Registered before sending: Home Assistant may emit the initial event right after the result.
        $this->subscriptionsByWsId[$id] = $subscription;

        try {
            $this->send($connection, $id, [
                'id' => $id,
                'type' => 'subscribe_entities',
                'entity_ids' => $subscription->entityIds,
            ], null);
        } catch (\Throwable $e) {
            unset($this->subscriptionsByWsId[$id]);
            $subscription->wsId = null;

            throw $e;
        }
    }

    private function detach(EntitySubscription $subscription): void
    {
        unset($this->subscriptions[spl_object_id($subscription)]);

        $wsId = $subscription->wsId;
        if (null === $wsId) {
            return;
        }
        unset($this->subscriptionsByWsId[$wsId]);
        $subscription->wsId = null;

        if (null === $this->connection) {
            return;
        }
        try {
            $this->request('unsubscribe_events', ['subscription' => $wsId]);
        } catch (HomeAssistantException $e) {
            $this->logger->debug('Unsubscribe failed: {message}', ['message' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @throws HomeAssistantException
     */
    private function request(string $type, array $fields = [], ?float $timeout = null): mixed
    {
        $connection = $this->requireConnection();
        $id = $this->requests->nextId();

        return $this->send($connection, $id, ['id' => $id, 'type' => $type] + $fields, $timeout);
    }

    /**
     * @param array<string, mixed> $command
     *
     * @throws HomeAssistantException
     */
    private function send(WebsocketConnection $connection, int $id, array $command, ?float $timeout): mixed
    {
        $future = $this->requests->register($id);

        try {
            $connection->sendText(Payload::encode($command));
        } catch (HomeAssistantException $e) {
            $this->requests->forget($id);

            throw $e;
        } catch (\Throwable $e) {
            $this->requests->forget($id);

            throw new ConnectionLostException('Cannot send to Home Assistant: '.$e->getMessage(), 0, $e);
        }

        $timeout ??= $this->settings->commandTimeout;
        try {
            return $future->await(new TimeoutCancellation($timeout));
        } catch (CancelledException $e) {
            $this->requests->forget($id);

            throw new CommandTimeoutException(\sprintf('Home Assistant did not answer "%s" within %ss.', \is_string($command['type'] ?? null) ? $command['type'] : 'command', $timeout), 0, $e);
        }
    }

    /**
     * @throws ConnectionLostException
     */
    private function requireConnection(): WebsocketConnection
    {
        return $this->connection ?? throw new ConnectionLostException('Not connected to Home Assistant.');
    }
}
