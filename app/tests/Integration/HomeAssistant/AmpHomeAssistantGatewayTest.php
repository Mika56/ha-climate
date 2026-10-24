<?php

declare(strict_types=1);

namespace App\Tests\Integration\HomeAssistant;

use App\Application\Port\HomeAssistant\Area;
use App\Application\Port\HomeAssistant\AuthenticationFailedException;
use App\Application\Port\HomeAssistant\CommandFailedException;
use App\Application\Port\HomeAssistant\CommandTimeoutException;
use App\Application\Port\HomeAssistant\ConnectionLostException;
use App\Application\Port\HomeAssistant\DeviceEntry;
use App\Application\Port\HomeAssistant\EntityRegistryEntry;
use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\EntityStateChange;
use App\Application\Port\HomeAssistant\HomeAssistantConfig;
use App\Application\Port\HomeAssistant\ServiceCallResult;
use App\Application\Port\HomeAssistant\ServiceDescription;
use App\Application\Port\HomeAssistant\StateContext;
use App\Infrastructure\HomeAssistant\AmpHomeAssistantGateway;
use App\Infrastructure\HomeAssistant\CompressedStateDecoder;
use App\Infrastructure\HomeAssistant\ConnectionSettings;
use App\Infrastructure\HomeAssistant\DecodedStateUpdate;
use App\Infrastructure\HomeAssistant\EntitySubscription;
use App\Infrastructure\HomeAssistant\Payload;
use App\Infrastructure\HomeAssistant\PendingRequests;
use App\Infrastructure\HomeAssistant\ReconnectBackoff;
use App\Infrastructure\HomeAssistant\ResultMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

#[CoversClass(AmpHomeAssistantGateway::class)]
#[CoversClass(EntitySubscription::class)]
#[UsesClass(CommandFailedException::class)]
#[UsesClass(CompressedStateDecoder::class)]
#[UsesClass(ConnectionSettings::class)]
#[UsesClass(DecodedStateUpdate::class)]
#[UsesClass(Payload::class)]
#[UsesClass(PendingRequests::class)]
#[UsesClass(ReconnectBackoff::class)]
#[UsesClass(ResultMapper::class)]
#[UsesClass(Area::class)]
#[UsesClass(DeviceEntry::class)]
#[UsesClass(EntityRegistryEntry::class)]
#[UsesClass(EntityState::class)]
#[UsesClass(EntityStateChange::class)]
#[UsesClass(HomeAssistantConfig::class)]
#[UsesClass(ServiceCallResult::class)]
#[UsesClass(ServiceDescription::class)]
#[UsesClass(StateContext::class)]
final class AmpHomeAssistantGatewayTest extends TestCase
{
    private FakeHomeAssistantServer $server;
    private ArrayLogger $logger;

    /** @var list<AmpHomeAssistantGateway> */
    private array $gateways = [];

    protected function setUp(): void
    {
        $this->server = FakeHomeAssistantServer::start();
        $this->logger = new ArrayLogger();
    }

    protected function tearDown(): void
    {
        foreach ($this->gateways as $gateway) {
            $gateway->disconnect();
        }
        $this->server->stop();
    }

    public function testConnectAuthenticatesAndIsIdempotent(): void
    {
        $gateway = $this->gateway();

        $gateway->connect();
        $gateway->connect();

        self::assertSame(1, $this->server->authenticatedConnections);
    }

    public function testConcurrentConnectsShareOneConnection(): void
    {
        $gateway = $this->gateway();

        await([async($gateway->connect(...)), async($gateway->connect(...))]);

        self::assertSame(1, $this->server->authenticatedConnections);
    }

    public function testInvalidTokenFailsAuthentication(): void
    {
        $gateway = $this->gateway(token: 'wrong');

        $this->expectException(AuthenticationFailedException::class);
        $this->expectExceptionMessage('Invalid access token');

        $gateway->connect();
    }

    public function testUnreachableServerRaisesConnectionLost(): void
    {
        $other = FakeHomeAssistantServer::start();
        $url = $other->url();
        $other->stop();
        $gateway = $this->gateway(url: $url);

        $this->expectException(ConnectionLostException::class);

        $gateway->connect();
    }

    public function testCommandsRequireAConnection(): void
    {
        $gateway = $this->gateway();

        $this->expectException(ConnectionLostException::class);

        $gateway->getStates();
    }

    public function testReadCommandsAreMappedToDtos(): void
    {
        $gateway = $this->connected();

        $config = $gateway->getConfig();
        self::assertSame('fr', $config->language);
        self::assertSame('Europe/Paris', $config->timeZone);
        self::assertSame('°C', $config->temperatureUnit);

        $states = $gateway->getStates();
        self::assertCount(1, $states);
        self::assertSame('sensor.temp', $states[0]->entityId);
        self::assertSame('19.5', $states[0]->state);

        $services = $gateway->getServices();
        self::assertSame('Phone', $services['notify']['mobile_app_phone']->name);

        self::assertSame('living', $gateway->listAreas()[0]->id);
        self::assertSame('dev1', $gateway->listDevices()[0]->id);
        self::assertSame('climate.living', $gateway->listEntityRegistry()[0]->entityId);
    }

    public function testCallServiceSendsTheCommandAndReturnsTheContext(): void
    {
        $gateway = $this->connected();

        $result = $gateway->callService('climate', 'set_temperature', ['temperature' => 21.5], ['entity_id' => 'climate.living']);
        $gateway->callService('climate', 'turn_off');

        self::assertSame('call-1', $result->context->id);
        self::assertSame('u1', $result->context->userId);
        $commands = $this->server->commandsOfType('call_service');
        self::assertSame('climate', $commands[0]['domain']);
        self::assertSame('set_temperature', $commands[0]['service']);
        self::assertSame(['temperature' => 21.5], $commands[0]['service_data']);
        self::assertSame(['entity_id' => 'climate.living'], $commands[0]['target']);
        self::assertFalse($commands[0]['return_response']);
        self::assertArrayNotHasKey('service_data', $commands[1], 'empty data would be encoded as a JSON list');
        self::assertArrayNotHasKey('target', $commands[1]);
        self::assertGreaterThan($commands[0]['id'], $commands[1]['id']);
    }

    public function testFailedCommandRaisesCommandFailedException(): void
    {
        $gateway = $this->connected();

        try {
            $gateway->callService('broken', 'service');
            self::fail('Expected a CommandFailedException.');
        } catch (CommandFailedException $e) {
            self::assertSame('service_not_found', $e->errorCode);
            self::assertStringContainsString('Service not found.', $e->getMessage());
        }
    }

    public function testUnansweredCommandTimesOutAndLateAnswerIsIgnored(): void
    {
        $this->server->silentTypes = ['get_services'];
        $gateway = $this->connected(commandTimeout: 0.1);

        try {
            $gateway->getServices();
            self::fail('Expected a CommandTimeoutException.');
        } catch (CommandTimeoutException $e) {
            self::assertStringContainsString('get_services', $e->getMessage());
        }

        $late = $this->server->commandsOfType('get_services')[0]['id'];
        \assert(\is_int($late));
        $this->server->reply($late, []);
        self::assertSame('fr', $gateway->getConfig()->language, 'the connection keeps working');
    }

    public function testSubscriptionReceivesInitialStateChangesAndRemovals(): void
    {
        $this->server->setEntity('sensor.t', '19', ['unit' => '°C'], 'ctx-a', 1700000000.0);
        $gateway = $this->connected();
        $changes = [];

        $subscription = $gateway->subscribeEntities(['sensor.t', 'sensor.other'], static function (EntityStateChange $change) use (&$changes): void {
            $changes[] = $change;
        });
        $this->waitUntil(static function () use (&$changes): bool {
            return 1 === \count($changes);
        }, 'the initial state');

        self::assertNull($changes[0]->old);
        self::assertSame('19', $changes[0]->new?->state);
        self::assertSame('ctx-a', $changes[0]->new->context->id);

        $this->server->pushEvent(['c' => ['sensor.t' => ['+' => ['s' => '20', 'a' => ['extra' => 1], 'c' => ['id' => 'ctx-b', 'user_id' => 'me'], 'lc' => 1700000010.0]]]]);
        $this->waitUntil(static function () use (&$changes): bool {
            return 2 === \count($changes);
        }, 'the state change');
        self::assertSame('19', $changes[1]->old?->state);
        self::assertSame('20', $changes[1]->new?->state);
        self::assertSame(['unit' => '°C', 'extra' => 1], $changes[1]->new->attributes);
        self::assertSame('me', $changes[1]->new->context->userId);

        $this->server->pushEvent(['r' => ['sensor.t']]);
        $this->waitUntil(static function () use (&$changes): bool {
            return 3 === \count($changes);
        }, 'the removal');
        self::assertTrue($changes[2]->isRemoval());

        $command = $this->server->commandsOfType('subscribe_entities')[0];
        self::assertSame(['sensor.t', 'sensor.other'], $command['entity_ids']);
        self::assertSame([], $this->server->commandsOfType('subscribe_events'));

        $subscription->unsubscribe();
        $subscription->unsubscribe();
        self::assertSame(0, $this->server->subscriptionCount());
        $unsubscribe = $this->server->commandsOfType('unsubscribe_events');
        self::assertCount(1, $unsubscribe);
        self::assertSame($command['id'], $unsubscribe[0]['subscription']);
    }

    public function testListenerMayCallTheGatewayWithoutDeadlock(): void
    {
        $this->server->setEntity('sensor.t', '19');
        $gateway = $this->connected();
        $contexts = [];

        $gateway->subscribeEntities(['sensor.t'], static function () use ($gateway, &$contexts): void {
            $contexts[] = $gateway->callService('climate', 'turn_on')->context->id;
        });

        $this->waitUntil(static function () use (&$contexts): bool {
            return [] !== $contexts;
        }, 'the service call made by the listener');
        self::assertSame(['call-1'], $contexts);
    }

    public function testFailingListenerDoesNotStopDelivery(): void
    {
        $this->server->setEntity('sensor.t', '19');
        $gateway = $this->connected();
        $seen = [];

        $gateway->subscribeEntities(['sensor.t'], static function (EntityStateChange $change) use (&$seen): void {
            $seen[] = $change->new?->state;
            if ('19' === $change->new?->state) {
                throw new \RuntimeException('listener bug');
            }
        });
        $this->waitUntil(static function () use (&$seen): bool {
            return 1 === \count($seen);
        }, 'the first change');
        $this->server->pushEvent(['c' => ['sensor.t' => ['+' => ['s' => '20']]]]);
        $this->waitUntil(static function () use (&$seen): bool {
            return 2 === \count($seen);
        }, 'the second change');

        self::assertSame(['19', '20'], $seen);
        self::assertTrue($this->logger->hasMessageContaining('listener failed'));
    }

    public function testMalformedTrafficIsIgnored(): void
    {
        $this->server->garbageAfterAuth = [
            'not json',
            '[1,2]',
            '{"id":9999,"type":"result","success":true}',
            '{"id":777,"type":"event","event":{"a":{}}}',
            '{"type":"auth_ok"}',
            '{"id":5,"type":"mystery"}',
            '{"id":6,"type":"event","event":"nope"}',
        ];
        $this->server->setEntity('sensor.t', '1');
        $gateway = $this->connected();
        $seen = [];
        $gateway->subscribeEntities(['sensor.t'], static function (EntityStateChange $change) use (&$seen): void {
            $seen[] = $change->new?->state;
        });
        $this->waitUntil(static function () use (&$seen): bool {
            return 1 === \count($seen);
        }, 'the initial state');

        $this->server->pushEvent(['a' => 'not an object']);
        $this->server->pushEvent(['c' => ['sensor.t' => ['+' => ['s' => '2']]]]);

        $this->waitUntil(static function () use (&$seen): bool {
            return 2 === \count($seen);
        }, 'the valid change after the invalid ones');
        self::assertTrue($this->logger->has('warning'));
        self::assertSame('fr', $gateway->getConfig()->language);
    }

    public function testSubscribingNeedsEntitiesAndAConnection(): void
    {
        $gateway = $this->gateway();
        try {
            $gateway->subscribeEntities(['sensor.t'], static function (): void {
            });
            self::fail('Expected a ConnectionLostException.');
        } catch (ConnectionLostException) {
            // expected
        }

        $gateway->connect();
        $this->expectException(\InvalidArgumentException::class);
        $gateway->subscribeEntities([], static function (): void {
        });
    }

    public function testRefusedSubscriptionIsNotKept(): void
    {
        $this->server->failTypes = ['subscribe_entities'];
        $gateway = $this->connected();

        try {
            $gateway->subscribeEntities(['sensor.t'], static function (): void {
            });
            self::fail('Expected a CommandFailedException.');
        } catch (CommandFailedException $e) {
            self::assertSame('denied', $e->errorCode);
        }

        $this->server->failTypes = [];
        $gateway->disconnect();
        $gateway->connect();
        self::assertCount(1, $this->server->commandsOfType('subscribe_entities'), 'nothing is resubscribed');
    }

    public function testReconnectionRestoresSubscriptionsAndReportsWhatChangedMeanwhile(): void
    {
        $this->server->setEntity('sensor.t', '1', [], 'ctx-1', 1700000000.0);
        $gateway = $this->connected();
        $lost = 0;
        $back = 0;
        $gateway->onConnectionLost(static function () use (&$lost): void {
            ++$lost;
        });
        $gateway->onReconnected(static function () use (&$back): void {
            ++$back;
        });
        $changes = [];
        $gateway->subscribeEntities(['sensor.t'], static function (EntityStateChange $change) use (&$changes): void {
            $changes[] = $change;
        });
        $this->waitUntil(static function () use (&$changes): bool {
            return 1 === \count($changes);
        }, 'the initial state');

        $this->server->setEntity('sensor.t', '2', [], 'ctx-2', 1700000100.0);
        $this->server->dropClients();
        $this->waitUntil(static function () use (&$back, &$lost): bool {
            return 1 === $lost && 1 === $back;
        }, 'the reconnection');
        $this->waitUntil(static function () use (&$changes): bool {
            return 2 === \count($changes);
        }, 'the resynchronised state');

        self::assertSame('1', $changes[1]->old?->state);
        self::assertSame('2', $changes[1]->new?->state);
        self::assertSame(2, $this->server->authenticatedConnections);
        self::assertCount(2, $this->server->commandsOfType('subscribe_entities'));

        $this->server->pushEvent(['c' => ['sensor.t' => ['+' => ['s' => '3']]]]);
        $this->waitUntil(static function () use (&$changes): bool {
            return 3 === \count($changes);
        }, 'a change on the new subscription');
        self::assertSame('2', $changes[2]->old?->state);
        self::assertSame('3', $changes[2]->new?->state);
        self::assertSame('fr', $gateway->getConfig()->language);
    }

    public function testPendingRequestsFailWhenTheConnectionDrops(): void
    {
        $this->server->silentTypes = ['get_services'];
        $gateway = $this->connected();
        $back = 0;
        $gateway->onReconnected(static function () use (&$back): void {
            ++$back;
        });

        $pending = async($gateway->getServices(...));
        $this->waitUntil(fn (): bool => [] !== $this->server->commandsOfType('get_services'), 'the command to reach the server');
        $this->server->dropClients();

        try {
            $pending->await();
            self::fail('Expected a ConnectionLostException.');
        } catch (ConnectionLostException) {
            // expected
        }
        $this->waitUntil(static function () use (&$back): bool {
            return 1 === $back;
        }, 'the reconnection');
        self::assertSame(1, $back);
    }

    public function testReconnectionRetriesUntilTheServerAccepts(): void
    {
        $gateway = $this->connected();
        $back = 0;
        $gateway->onReconnected(static function () use (&$back): void {
            ++$back;
        });

        $this->server->rejectNextConnections = 2;
        $this->server->dropClients();
        $this->waitUntil(static function () use (&$back): bool {
            return 1 === $back;
        }, 'the reconnection after two refusals');

        self::assertSame(2, $this->server->authenticatedConnections);
        self::assertTrue($this->logger->hasMessageContaining('Reconnection failed'));
    }

    public function testFailingHooksDoNotBreakReconnection(): void
    {
        $gateway = $this->connected();
        $gateway->onConnectionLost(static function (): void {
            throw new \RuntimeException('lost hook bug');
        });
        $back = 0;
        $gateway->onReconnected(static function () use (&$back): void {
            ++$back;
        });

        $this->server->dropClients();

        $this->waitUntil(static function () use (&$back): bool {
            return 1 === $back;
        }, 'the reconnection');
        self::assertTrue($this->logger->hasMessageContaining('lost hook bug'));
    }

    public function testKeepAliveDropsAConnectionThatStopsAnsweringPings(): void
    {
        $this->server->ignorePing = true;
        $gateway = $this->connected(pingInterval: 0.05, pongTimeout: 0.1);
        $lost = 0;
        $back = 0;
        $gateway->onConnectionLost(static function () use (&$lost): void {
            ++$lost;
        });
        $gateway->onReconnected(static function () use (&$back): void {
            ++$back;
        });

        $this->waitUntil(static function () use (&$lost): bool {
            return $lost >= 1;
        }, 'the keep-alive to give up');
        $this->server->ignorePing = false;
        $this->waitUntil(static function () use (&$back): bool {
            return $back >= 1;
        }, 'the reconnection');

        self::assertNotSame([], $this->server->commandsOfType('ping'));
        self::assertGreaterThanOrEqual(2, $this->server->authenticatedConnections);
        self::assertTrue($this->logger->hasMessageContaining('No pong'));
    }

    public function testHealthyConnectionSurvivesPings(): void
    {
        $gateway = $this->connected(pingInterval: 0.03, pongTimeout: 0.5);

        $this->waitUntil(fn (): bool => \count($this->server->commandsOfType('ping')) >= 3, 'three pings');

        self::assertSame(1, $this->server->authenticatedConnections);
        self::assertSame('fr', $gateway->getConfig()->language);
    }

    public function testDisconnectIsFinalUntilConnectIsCalledAgain(): void
    {
        $gateway = $this->connected();
        $lost = 0;
        $gateway->onConnectionLost(static function () use (&$lost): void {
            ++$lost;
        });
        $subscription = $gateway->subscribeEntities(['sensor.t'], static function (): void {
        });

        $gateway->disconnect();
        delay(0.1);

        self::assertSame(0, $lost, 'a voluntary disconnection is not a loss');
        self::assertSame(1, $this->server->authenticatedConnections, 'no reconnection');
        $subscription->unsubscribe();
        try {
            $gateway->getStates();
            self::fail('Expected a ConnectionLostException.');
        } catch (ConnectionLostException) {
            // expected
        }

        $gateway->connect();
        self::assertSame(2, $this->server->authenticatedConnections);
        self::assertSame('fr', $gateway->getConfig()->language);
    }

    public function testConnectWaitsForAnOngoingReconnection(): void
    {
        $gateway = $this->connected();
        $this->server->rejectNextConnections = 1;
        $this->server->dropClients();
        delay(0.03);

        $gateway->connect();

        self::assertSame('fr', $gateway->getConfig()->language);
    }

    public function testUnsubscribingWhileDisconnectedDoesNotFail(): void
    {
        $gateway = $this->connected();
        $subscription = $gateway->subscribeEntities(['sensor.t'], static function (): void {
        });
        $this->server->silentTypes = ['unsubscribe_events'];
        $gateway->disconnect();

        $subscription->unsubscribe();

        self::assertSame(0, $this->server->subscriptionCount());
    }

    public function testUnsubscribeSwallowsErrors(): void
    {
        $gateway = $this->connected(commandTimeout: 0.1);
        $subscription = $gateway->subscribeEntities(['sensor.t'], static function (): void {
        });
        $this->server->silentTypes = ['unsubscribe_events'];

        $subscription->unsubscribe();

        self::assertTrue($this->logger->hasMessageContaining('Unsubscribe failed'));
    }

    private function gateway(
        ?string $url = null,
        string $token = FakeHomeAssistantServer::TOKEN,
        float $commandTimeout = 2.0,
        float $pingInterval = 30.0,
        float $pongTimeout = 10.0,
    ): AmpHomeAssistantGateway {
        $gateway = new AmpHomeAssistantGateway(
            new ConnectionSettings($url ?? $this->server->url(), $token, $commandTimeout, $pingInterval, $pongTimeout),
            $this->logger,
            new ReconnectBackoff(0.02, 0.05, static fn (): float => 0.0),
        );
        $this->gateways[] = $gateway;

        return $gateway;
    }

    private function connected(float $commandTimeout = 2.0, float $pingInterval = 30.0, float $pongTimeout = 10.0): AmpHomeAssistantGateway
    {
        $gateway = $this->gateway(commandTimeout: $commandTimeout, pingInterval: $pingInterval, pongTimeout: $pongTimeout);
        $gateway->connect();

        return $gateway;
    }

    /**
     * @param \Closure(): bool $condition
     */
    private function waitUntil(\Closure $condition, string $what, float $timeout = 3.0): void
    {
        $deadline = microtime(true) + $timeout;
        while (!$condition()) {
            if (microtime(true) > $deadline) {
                self::fail('Timed out waiting for '.$what.'. Log: '.json_encode($this->logger->records));
            }
            delay(0.005);
        }
    }
}
