<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Port\HomeAssistant;

use App\Application\Port\HomeAssistant\Area;
use App\Application\Port\HomeAssistant\ConnectionLostException;
use App\Application\Port\HomeAssistant\DeviceEntry;
use App\Application\Port\HomeAssistant\EntityRegistryEntry;
use App\Application\Port\HomeAssistant\EntityStateChange;
use App\Application\Port\HomeAssistant\HomeAssistantConfig;
use App\Application\Port\HomeAssistant\ServiceDescription;
use App\Application\Port\HomeAssistant\StateContext;
use App\Tests\Double\FakeHomeAssistantGateway;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class FakeHomeAssistantGatewayTest extends TestCase
{
    public function testSubscriberGetsCurrentStateThenChanges(): void
    {
        $ha = new FakeHomeAssistantGateway();
        $ha->seedState('sensor.t', '19');
        $ha->connect();
        /** @var list<EntityStateChange> $received */
        $received = [];

        $ha->subscribeEntities(['sensor.t', 'sensor.other'], static function (EntityStateChange $change) use (&$received): void {
            $received[] = $change;
        });
        $ha->pushStateChange('sensor.t', '20', ['unit' => 'C']);
        $ha->pushStateChange('sensor.t', '21');
        $ha->pushStateChange('sensor.unrelated', '1');

        self::assertCount(3, $received);
        self::assertNull($received[0]->old);
        self::assertSame('19', $received[0]->new?->state);
        self::assertSame('19', $received[1]->old?->state);
        self::assertSame(['unit' => 'C'], $received[2]->new?->attributes, 'attributes are kept when not given');
    }

    public function testUnsubscribeStopsNotifications(): void
    {
        $ha = new FakeHomeAssistantGateway();
        $ha->connect();
        $count = 0;
        $subscription = $ha->subscribeEntities(['sensor.t'], static function () use (&$count): void {
            ++$count;
        });

        $ha->pushStateChange('sensor.t', '1');
        $subscription->unsubscribe();
        $subscription->unsubscribe();
        $ha->pushStateChange('sensor.t', '2');

        self::assertSame(1, $count);
        self::assertSame(0, $ha->subscriptionCount());
    }

    public function testRemovalIsReported(): void
    {
        $ha = new FakeHomeAssistantGateway();
        $ha->seedState('sensor.t', '1');
        $ha->connect();
        /** @var list<EntityStateChange> $changes */
        $changes = [];
        $ha->subscribeEntities(['sensor.t'], static function (EntityStateChange $change) use (&$changes): void {
            $changes[] = $change;
        });

        $ha->removeEntity('sensor.t');
        $ha->removeEntity('sensor.t');

        self::assertCount(2, $changes);
        self::assertTrue($changes[1]->isRemoval());
        self::assertNull($ha->state('sensor.t'));
    }

    public function testServiceCallsAreRecordedWithDistinctContexts(): void
    {
        $ha = new FakeHomeAssistantGateway();
        $ha->connect();

        $first = $ha->callService('climate', 'set_temperature', ['temperature' => 21], ['entity_id' => 'climate.a']);
        $second = $ha->callService('notify', 'phone', ['message' => 'hi']);

        self::assertNotSame($first->context->id, $second->context->id);
        self::assertCount(2, $ha->serviceCalls());
        $calls = $ha->serviceCallsTo('climate', 'set_temperature');
        self::assertCount(1, $calls);
        self::assertSame(['temperature' => 21], $calls[0]->serviceData);
        self::assertSame(['entity_id' => 'climate.a'], $calls[0]->target);
        self::assertSame($first->context, $calls[0]->context);

        $ha->clearServiceCalls();
        self::assertSame([], $ha->serviceCalls());
    }

    public function testStatesAndMetadata(): void
    {
        $now = new \DateTimeImmutable('2024-01-01T00:00:00+00:00');
        $ha = new FakeHomeAssistantGateway(static fn (): \DateTimeImmutable => $now);
        $ha->seedState('sensor.t', '1', [], new StateContext('mine', null, 'user'));
        $ha->connect();
        $ha->withConfig(new HomeAssistantConfig('fr', 'Europe/Paris', '°C', '2025.1'))
            ->withServices(['notify' => ['phone' => new ServiceDescription('notify', 'phone')]])
            ->withRegistries([new Area('a', 'A')], [new DeviceEntry('d')], [new EntityRegistryEntry('sensor.t')]);

        self::assertCount(1, $ha->getStates());
        $state = $ha->state('sensor.t');
        self::assertNotNull($state);
        self::assertSame('mine', $state->context->id);
        self::assertSame($now, $state->lastUpdated);
        self::assertSame('fr', $ha->getConfig()->language);
        self::assertArrayHasKey('phone', $ha->getServices()['notify']);
        self::assertSame('A', $ha->listAreas()[0]->name);
        self::assertSame('d', $ha->listDevices()[0]->id);
        self::assertSame('sensor.t', $ha->listEntityRegistry()[0]->entityId);
    }

    public function testLastChangedOnlyMovesWhenTheStateChanges(): void
    {
        $times = [new \DateTimeImmutable('2024-01-01T00:00:00+00:00'), new \DateTimeImmutable('2024-01-01T00:05:00+00:00')];
        $ha = new FakeHomeAssistantGateway(static function () use (&$times): \DateTimeImmutable {
            return array_shift($times) ?? new \DateTimeImmutable('2024-01-01T00:10:00+00:00');
        });

        $ha->seedState('sensor.t', '1');
        $second = $ha->seedState('sensor.t', '1', ['a' => 1]);

        self::assertSame('00:00:00', $second->lastChanged->format('H:i:s'));
        self::assertSame('00:05:00', $second->lastUpdated->format('H:i:s'));
    }

    public function testConnectionLossAndReconnectResyncSubscribers(): void
    {
        $ha = new FakeHomeAssistantGateway();
        $ha->seedState('sensor.t', '1');
        $ha->connect();
        $events = [];
        $ha->onConnectionLost(static function () use (&$events): void {
            $events[] = 'lost';
        });
        $ha->onReconnected(static function () use (&$events): void {
            $events[] = 'back';
        });
        /** @var \ArrayObject<int, EntityStateChange> $changes */
        $changes = new \ArrayObject();
        $ha->subscribeEntities(['sensor.t'], static function (EntityStateChange $change) use ($changes): void {
            $changes[] = $change;
        });

        $ha->simulateConnectionLoss();
        $ha->simulateConnectionLoss();
        self::assertFalse($ha->isConnected());
        $ha->pushStateChange('sensor.t', '2');
        self::assertCount(1, $changes, 'nothing is delivered while down');
        try {
            $ha->getStates();
            self::fail('Expected a ConnectionLostException.');
        } catch (ConnectionLostException) {
            // expected
        }
        $ha->simulateReconnect();
        $ha->simulateReconnect();

        self::assertTrue($ha->isConnected());
        self::assertSame(['lost', 'back'], $events);
        self::assertCount(2, $changes);
        $resync = $changes->getArrayCopy()[1];
        self::assertSame('1', $resync->old?->state);
        self::assertSame('2', $resync->new?->state);
    }

    public function testNotConnectedGatewayRefusesCalls(): void
    {
        $ha = new FakeHomeAssistantGateway();

        $this->expectException(ConnectionLostException::class);

        $ha->getStates();
    }

    public function testConnectFailsWhileTheConnectionIsDown(): void
    {
        $ha = new FakeHomeAssistantGateway();
        $ha->simulateConnectionLoss();

        $this->expectException(ConnectionLostException::class);

        $ha->connect();
    }

    public function testDisconnectDropsSubscriptions(): void
    {
        $ha = new FakeHomeAssistantGateway();
        $ha->connect();
        $ha->subscribeEntities(['sensor.t'], static function (): void {
        });

        $ha->disconnect();

        self::assertSame(0, $ha->subscriptionCount());
        self::assertFalse($ha->isConnected());
    }
}
