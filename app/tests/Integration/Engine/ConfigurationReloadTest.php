<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Engine\Evaluation\LiveStatusPublisher;
use App\Application\Engine\Lifecycle\ConfigurationReloader;
use App\Application\Engine\State\StateStore;
use App\Application\Engine\State\StateSynchronizer;
use App\Application\Engine\State\SubscriptionManager;
use App\Application\Port\HomeAssistant\StateContext;
use App\Domain\Climate\Season;
use App\Infrastructure\Persistence\Repository\RoomRepository;
use App\Infrastructure\Persistence\Repository\TimerRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;

#[CoversClass(ConfigurationReloader::class)]
#[CoversClass(SubscriptionManager::class)]
#[CoversClass(StateSynchronizer::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure')]
final class ConfigurationReloadTest extends EngineTestCase
{
    public function testReloadResubscribesWithTheNewEntitySetAndEvaluatesTheNewRoom(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->seedDevice('climate.bedroom', 'off');
        $this->homeAssistant()->seedState('sensor.bedroom_temperature', '18.0');
        $this->startEngine();
        self::assertSame(1, $this->homeAssistant()->subscriptionCount());

        // Nothing reaches the engine about a room it does not know yet.
        $this->homeAssistant()->pushStateChange('climate.bedroom', 'off', ['temperature' => 7.0]);
        self::assertSame([], $this->homeAssistant()->serviceCalls());

        $this->createRoom('Bedroom', 'climate.bedroom', sensor: 'sensor.bedroom_temperature');
        $this->service(ConfigurationReloader::class)->reload();

        self::assertSame(1, $this->homeAssistant()->subscriptionCount(), 'The old subscription was replaced, not duplicated.');
        $calls = $this->homeAssistant()->serviceCalls();
        self::assertCount(1, $calls);
        self::assertSame('climate.bedroom', $calls[0]->target['entity_id']);
        self::assertCount(2, $this->service(LiveStatusPublisher::class)->snapshot());
        self::assertNotNull($this->service(StateStore::class)->get('sensor.bedroom_temperature'));

        // And the new entities are now followed.
        $this->homeAssistant()->clearServiceCalls();
        $this->homeAssistant()->pushStateChange('sensor.bedroom_temperature', '17.5');
        $bedroom = array_values(array_filter($this->service(LiveStatusPublisher::class)->snapshot(), static fn ($status): bool => 'Bedroom' === $status->name));
        self::assertSame(17.5, $bedroom[0]->currentTemperature?->toFloat());
        self::assertCount(1, $this->auditEntries('audit.configuration.reloaded'));
    }

    public function testReloadPicksUpAChangeOfSeasonAndForgetsDeletedRooms(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $bedroom = $this->createRoom('Bedroom', 'climate.bedroom', sensor: 'sensor.bedroom_temperature');
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedDevice('climate.bedroom', 'heat', 21.0);
        $this->startEngine();
        $boundary = 'room.boundary.'.$bedroom->getId()->toRfc4122();
        self::assertNotNull($this->service(TimerRepository::class)->findOneByKey($boundary));

        $this->deleteRoom($bedroom);
        $this->service(ConfigurationReloader::class)->reload();

        self::assertCount(1, $this->service(LiveStatusPublisher::class)->snapshot());
        self::assertNull($this->service(TimerRepository::class)->findOneByKey($boundary));
        self::assertNull($this->service(StateStore::class)->get('climate.bedroom'));
        self::assertNull($this->service(RoomRepository::class)->find($bedroom->getId()));
        self::assertSame([], $this->homeAssistant()->serviceCalls());
    }

    public function testReloadWithoutAnyRoomDropsTheSubscription(): void
    {
        $this->setSeason(Season::Winter);
        $room = $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->startEngine();
        self::assertSame(1, $this->homeAssistant()->subscriptionCount());

        $this->deleteRoom($room);
        $this->service(ConfigurationReloader::class)->reload();

        self::assertSame(0, $this->homeAssistant()->subscriptionCount(), 'Never subscribe to everything.');
    }

    public function testAReloadDuringAnOutageIsCompletedWhenTheConnectionIsBack(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedDevice('climate.bedroom', 'off');
        $this->startEngine();

        $this->homeAssistant()->simulateConnectionLoss();
        $this->createRoom('Bedroom', 'climate.bedroom', sensor: 'sensor.bedroom_temperature');
        $this->service(ConfigurationReloader::class)->reload();
        self::assertCount(2, $this->service(LiveStatusPublisher::class)->snapshot(), 'The rooms are evaluated with what is known.');
        self::assertSame([], $this->homeAssistant()->serviceCalls(), 'Nothing can be sent while disconnected.');

        $this->homeAssistant()->simulateReconnect();

        $calls = $this->homeAssistant()->serviceCalls();
        self::assertCount(1, $calls);
        self::assertSame('climate.bedroom', $calls[0]->target['entity_id']);
        self::assertSame(1, $this->homeAssistant()->subscriptionCount());
    }

    public function testReconnectionReplaysWhatChangedWhileDisconnectedAndEvaluatesEveryRoom(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->startEngine();

        $this->homeAssistant()->simulateConnectionLoss();
        $this->homeAssistant()->pushStateChange(self::SENSOR, '17.0');
        $this->homeAssistant()->pushStateChange(self::DEVICE, 'heat', ['temperature' => 25.0], new StateContext('ui-1', null, 'user-42'));
        self::assertCount(1, $this->auditEntries('audit.connection.lost'));

        $this->homeAssistant()->simulateReconnect();

        self::assertCount(1, $this->auditEntries('audit.connection.restored'));
        self::assertSame(17.0, $this->service(LiveStatusPublisher::class)->snapshot()[0]->currentTemperature?->toFloat());
        $manual = $this->auditEntries('audit.manual_change.detected');
        self::assertCount(1, $manual);
        self::assertSame('user-42', $manual[0]->getActorId());
        self::assertSame([], $this->homeAssistant()->serviceCalls(), 'The manual setting made during the outage is kept, not reverted.');
        self::assertCount(1, $this->auditEntries('audit.override.manual_started'));
    }

    public function testAnEntityRemovedWhileDisconnectedIsForgottenOnReconnection(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->startEngine();

        $this->homeAssistant()->simulateConnectionLoss();
        $this->homeAssistant()->removeEntity(self::SENSOR);
        $this->homeAssistant()->simulateReconnect();

        self::assertNull($this->service(StateStore::class)->get(self::SENSOR));
        self::assertNull($this->service(LiveStatusPublisher::class)->snapshot()[0]->currentTemperature);
    }

    public function testCatchingUpDirectlyLearnsMissedChanges(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->startEngine();
        $this->homeAssistant()->clearServiceCalls();

        // A change the subscription never reported (state edited in place, no event).
        $this->homeAssistant()->seedState(self::SENSOR, '16.0');
        $this->service(StateSynchronizer::class)->catchUp();

        self::assertSame(16.0, $this->service(LiveStatusPublisher::class)->snapshot()[0]->currentTemperature?->toFloat());
    }
}
