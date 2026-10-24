<?php

declare(strict_types=1);

namespace App\Tests\Integration\HomeAssistant;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use App\Infrastructure\HomeAssistant\Payload;
use Psr\Log\NullLogger;

/**
 * In-process Home Assistant WebSocket server for integration tests (127.0.0.1, random port).
 *
 * It speaks enough of the real protocol: auth handshake, ping/pong, get_config, get_states,
 * subscribe_entities (initial "a" event built from {@see setEntity()}), unsubscribe_events, call_service,
 * get_services and the three registry lists. Knobs let tests script failures: {@see $silentTypes},
 * {@see $ignorePing}, {@see $rejectNextConnections}, {@see dropClients()}.
 */
final class FakeHomeAssistantServer implements WebsocketClientHandler
{
    public const string TOKEN = 'test-token';

    /**
     * Command types that never get an answer.
     *
     * @var list<string>
     */
    public array $silentTypes = [];

    /**
     * Command types answered with an error.
     *
     * @var list<string>
     */
    public array $failTypes = [];

    public bool $ignorePing = false;

    /** Number of upcoming connections closed right after "auth_required". */
    public int $rejectNextConnections = 0;

    /**
     * Raw text sent to every new client right after authentication (to test robustness).
     *
     * @var list<string>
     */
    public array $garbageAfterAuth = [];

    /**
     * Every command received after authentication.
     *
     * @var list<array<string, mixed>>
     */
    public array $received = [];

    /** Number of authenticated connections accepted so far. */
    public int $authenticatedConnections = 0;

    private SocketHttpServer $http;
    private int $port;
    private int $contextCounter = 0;

    /** @var array<int, WebsocketClient> */
    private array $clients = [];

    /** @var list<array{client: WebsocketClient, id: int, entityIds: list<string>}> */
    private array $subscriptions = [];

    /** @var array<string, array<string, mixed>> compressed entity objects */
    private array $entities = [];

    private ?WebsocketClient $lastClient = null;

    private function __construct()
    {
    }

    public static function start(): self
    {
        $server = new self();
        $logger = new NullLogger();
        $server->http = SocketHttpServer::createForDirectAccess($logger, enableCompression: false);
        $server->http->expose('127.0.0.1:0');
        $server->http->start(new Websocket($server->http, $logger, new Rfc6455Acceptor(), $server), new DefaultErrorHandler());

        $address = $server->http->getServers()[0]->getAddress();
        \assert($address instanceof InternetAddress);
        $server->port = $address->getPort();

        return $server;
    }

    public function url(): string
    {
        return \sprintf('ws://127.0.0.1:%d/api/websocket', $this->port);
    }

    public function stop(): void
    {
        $this->http->stop();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function setEntity(string $entityId, string $state, array $attributes = [], string $context = 'ctx-initial', float $changed = 1700000000.0): void
    {
        $this->entities[$entityId] = ['s' => $state, 'a' => $attributes, 'c' => $context, 'lc' => $changed];
    }

    /**
     * Sends a compressed `subscribe_entities` event to every subscriber.
     *
     * @param array<string, mixed> $event
     */
    public function pushEvent(array $event): void
    {
        foreach ($this->subscriptions as $subscription) {
            $this->send($subscription['client'], ['id' => $subscription['id'], 'type' => 'event', 'event' => $event]);
        }
    }

    /**
     * Answers a command that was left unanswered (see {@see $silentTypes}).
     */
    public function reply(int $id, mixed $result): void
    {
        if (null !== $this->lastClient) {
            $this->send($this->lastClient, ['id' => $id, 'type' => 'result', 'success' => true, 'result' => $result]);
        }
    }

    /**
     * Abruptly ends every connection.
     */
    public function dropClients(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function commandsOfType(string $type): array
    {
        return array_values(array_filter($this->received, static fn (array $command): bool => ($command['type'] ?? null) === $type));
    }

    public function subscriptionCount(): int
    {
        return \count($this->subscriptions);
    }

    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        if ($this->rejectNextConnections > 0) {
            --$this->rejectNextConnections;
            $client->close();

            return;
        }

        $this->send($client, ['type' => 'auth_required', 'ha_version' => '2025.1.0']);
        $auth = $client->receive();
        $token = null === $auth ? null : Payload::optionalString(Payload::decodeObject($auth->buffer()), 'access_token');
        if (self::TOKEN !== $token) {
            $this->send($client, ['type' => 'auth_invalid', 'message' => 'Invalid access token or password']);

            return;
        }
        $this->send($client, ['type' => 'auth_ok', 'ha_version' => '2025.1.0']);

        $clientId = $client->getId();
        $this->clients[$clientId] = $client;
        $this->lastClient = $client;
        ++$this->authenticatedConnections;
        foreach ($this->garbageAfterAuth as $garbage) {
            $client->sendText($garbage);
        }

        try {
            while (null !== ($message = $client->receive())) {
                $command = Payload::decodeObject($message->buffer());
                $this->received[] = $command;
                $this->handleCommand($client, $command);
            }
        } finally {
            unset($this->clients[$clientId]);
            $this->subscriptions = array_values(array_filter(
                $this->subscriptions,
                static fn (array $subscription): bool => $subscription['client'] !== $client,
            ));
        }
    }

    /**
     * @param array<string, mixed> $command
     */
    private function handleCommand(WebsocketClient $client, array $command): void
    {
        $id = $command['id'];
        $type = $command['type'];
        \assert(\is_int($id) && \is_string($type));

        if ('ping' === $type) {
            if (!$this->ignorePing) {
                $this->send($client, ['id' => $id, 'type' => 'pong']);
            }

            return;
        }
        if (\in_array($type, $this->silentTypes, true)) {
            return;
        }
        if (\in_array($type, $this->failTypes, true)) {
            $this->send($client, ['id' => $id, 'type' => 'result', 'success' => false, 'error' => ['code' => 'denied', 'message' => 'Denied by test.']]);

            return;
        }

        switch ($type) {
            case 'get_config':
                $this->result($client, $id, ['language' => 'fr', 'time_zone' => 'Europe/Paris', 'version' => '2025.1.0', 'unit_system' => ['temperature' => '°C']]);
                break;
            case 'get_states':
                $this->result($client, $id, [[
                    'entity_id' => 'sensor.temp',
                    'state' => '19.5',
                    'attributes' => ['unit_of_measurement' => '°C'],
                    'last_changed' => '2025-01-01T10:00:00+00:00',
                    'last_updated' => '2025-01-01T10:00:00+00:00',
                    'context' => ['id' => 'c1', 'parent_id' => null, 'user_id' => null],
                ]]);
                break;
            case 'get_services':
                $this->result($client, $id, ['notify' => ['mobile_app_phone' => ['name' => 'Phone', 'fields' => ['message' => ['required' => true]]]]]);
                break;
            case 'config/area_registry/list':
                $this->result($client, $id, [['area_id' => 'living', 'name' => 'Living room']]);
                break;
            case 'config/device_registry/list':
                $this->result($client, $id, [['id' => 'dev1', 'name' => 'Thermostat', 'area_id' => 'living']]);
                break;
            case 'config/entity_registry/list':
                $this->result($client, $id, [['entity_id' => 'climate.living', 'device_id' => 'dev1', 'platform' => 'demo']]);
                break;
            case 'call_service':
                if ('broken' === $command['domain']) {
                    $this->send($client, ['id' => $id, 'type' => 'result', 'success' => false, 'error' => ['code' => 'service_not_found', 'message' => 'Service not found.']]);
                    break;
                }
                $this->result($client, $id, ['context' => ['id' => 'call-'.++$this->contextCounter, 'parent_id' => null, 'user_id' => 'u1'], 'response' => null]);
                break;
            case 'subscribe_entities':
                $entityIds = array_values(array_filter((array) $command['entity_ids'], is_string(...)));
                $this->subscriptions[] = ['client' => $client, 'id' => $id, 'entityIds' => $entityIds];
                $this->result($client, $id, null);
                $initial = array_intersect_key($this->entities, array_flip($entityIds));
                $this->send($client, ['id' => $id, 'type' => 'event', 'event' => ['a' => $initial]]);
                break;
            case 'unsubscribe_events':
                $this->subscriptions = array_values(array_filter(
                    $this->subscriptions,
                    static fn (array $subscription): bool => $subscription['id'] !== $command['subscription'],
                ));
                $this->result($client, $id, null);
                break;
            default:
                $this->send($client, ['id' => $id, 'type' => 'result', 'success' => false, 'error' => ['code' => 'unknown_command', 'message' => 'Unknown command.']]);
        }
    }

    private function result(WebsocketClient $client, int $id, mixed $result): void
    {
        $this->send($client, ['id' => $id, 'type' => 'result', 'success' => true, 'result' => $result]);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function send(WebsocketClient $client, array $message): void
    {
        if ($client->isClosed()) {
            return;
        }
        $client->sendText(json_encode($message, \JSON_THROW_ON_ERROR));
    }
}
