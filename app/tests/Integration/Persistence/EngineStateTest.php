<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Domain\Climate\HvacMode;
use App\Domain\Decision\OverrideType;
use App\Infrastructure\Persistence\Entity\Alert;
use App\Infrastructure\Persistence\Entity\CommandLog;
use App\Infrastructure\Persistence\Entity\HeatingRate;
use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Entity\RoomOverride;
use App\Infrastructure\Persistence\Entity\Timer;
use App\Infrastructure\Persistence\Repository\AlertRepository;
use App\Infrastructure\Persistence\Repository\CommandLogRepository;
use App\Infrastructure\Persistence\Repository\HeatingRateRepository;
use App\Infrastructure\Persistence\Repository\RoomOverrideRepository;
use App\Infrastructure\Persistence\Repository\RoomRepository;
use App\Infrastructure\Persistence\Repository\TimerRepository;
use App\Infrastructure\Persistence\Sqlite\SqlitePragmaDriver;
use App\Infrastructure\Persistence\Sqlite\SqlitePragmaMiddleware;
use App\Infrastructure\Persistence\Support\Guard;
use App\Infrastructure\Persistence\Type\UtcDateTimeImmutableType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Uid\Uuid;

#[CoversClass(TimerRepository::class)]
#[CoversClass(AlertRepository::class)]
#[CoversClass(CommandLogRepository::class)]
#[CoversClass(RoomOverrideRepository::class)]
#[CoversClass(HeatingRateRepository::class)]
#[CoversClass(Timer::class)]
#[CoversClass(Alert::class)]
#[CoversClass(CommandLog::class)]
#[CoversClass(RoomOverride::class)]
#[CoversClass(HeatingRate::class)]
#[CoversClass(Room::class)]
#[CoversClass(RoomRepository::class)]
#[CoversClass(Guard::class)]
#[CoversClass(UtcDateTimeImmutableType::class)]
#[CoversClass(SqlitePragmaMiddleware::class)]
#[CoversClass(SqlitePragmaDriver::class)]
final class EngineStateTest extends PersistenceTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testTimersDueAndPending(): void
    {
        $repository = $this->service(TimerRepository::class);
        $roomId = Uuid::v7();
        $repository->save(new Timer('window.cut.a', 'window_cut', self::at('2026-03-01 10:00'), $roomId, ['delay' => 120]));
        $repository->save(new Timer('window.notify.a', 'window_notify', self::at('2026-03-01 10:30')));
        $repository->save(new Timer('preheat.b', 'preheat', self::at('2026-03-01 06:00'), null, ['target' => 20.5]));
        $this->em()->clear();

        $due = $repository->findDueBefore(self::at('2026-03-01 10:00'));
        self::assertSame(['preheat.b', 'window.cut.a'], array_map(static fn (Timer $t): string => $t->getKey(), $due));
        self::assertTrue($due[1]->getRoomId()?->equals($roomId));
        self::assertSame(['delay' => 120], $due[1]->getPayload());
        self::assertSame('window_cut', $due[1]->getType());
        self::assertTrue($due[1]->isDueAt(self::at('2026-03-01 10:00')));
        self::assertFalse($due[1]->isDueAt(self::at('2026-03-01 09:59')));

        self::assertSame(
            ['preheat.b', 'window.cut.a', 'window.notify.a'],
            array_map(static fn (Timer $t): string => $t->getKey(), $repository->findAllPending()),
        );
    }

    public function testTimerCanBeRescheduledAndDeletedByKey(): void
    {
        $repository = $this->service(TimerRepository::class);
        $repository->save(new Timer('t1', 'x', self::at('2026-03-01 10:00'), null, ['a' => 1]));
        $this->em()->clear();

        $timer = $repository->findOneByKey('t1');
        self::assertInstanceOf(Timer::class, $timer);
        $timer->reschedule(self::at('2026-03-01 11:00'));
        self::assertSame(['a' => 1], $timer->getPayload());
        $timer->reschedule(self::at('2026-03-01 12:00'), ['b' => 2]);
        $repository->save($timer);
        $this->em()->clear();

        $reloaded = $repository->findOneByKey('t1');
        self::assertEquals(self::at('2026-03-01 12:00'), $reloaded?->getDueAt());
        self::assertSame(['b' => 2], $reloaded?->getPayload());

        self::assertSame(1, $repository->deleteByKey('t1'));
        self::assertSame(0, $repository->deleteByKey('t1'));
        self::assertNull($repository->findOneByKey('t1'));
    }

    public function testTimerKeyIsUnique(): void
    {
        $repository = $this->service(TimerRepository::class);
        $repository->save(new Timer('same', 'x', self::at('2026-03-01 10:00')));

        $this->expectException(UniqueConstraintViolationException::class);
        $repository->save(new Timer('same', 'y', self::at('2026-03-01 11:00')));
    }

    public function testAlertLifecycle(): void
    {
        $room = new Room('Bedroom');
        $this->persistAndClear($room);
        $room = $this->service(RoomRepository::class)->find($room->getId());
        self::assertInstanceOf(Room::class, $room);

        $repository = $this->service(AlertRepository::class);
        $alert = new Alert('sensor.unavailable.sensor.temp', 'sensor_unavailable', self::at('2026-03-01 10:00'), 'sensor.temp', $room);
        $repository->save($alert);
        $repository->save(new Alert('device.unresponsive.climate.x', 'device_unresponsive', self::at('2026-03-01 09:00'), 'climate.x'));
        $this->em()->clear();

        $active = $repository->findActive();
        self::assertSame(
            ['device.unresponsive.climate.x', 'sensor.unavailable.sensor.temp'],
            array_map(static fn (Alert $a): string => $a->getKey(), $active),
        );

        $loaded = $repository->findOneByKey('sensor.unavailable.sensor.temp');
        self::assertInstanceOf(Alert::class, $loaded);
        self::assertSame('Bedroom', $loaded->getRoom()?->getName());
        self::assertSame('sensor.temp', $loaded->getEntityId());
        self::assertSame('sensor_unavailable', $loaded->getType());
        self::assertEquals(self::at('2026-03-01 10:00'), $loaded->getOpenedAt());
        $loaded->markNotified(self::at('2026-03-01 10:01'));
        $loaded->attachPersistentNotification('ha_climate_sensor_temp');
        $loaded->resolve(self::at('2026-03-01 10:05'));
        $loaded->resolve(self::at('2026-03-01 10:06'));
        $repository->save($loaded);
        $this->em()->clear();

        self::assertCount(1, $repository->findActive());
        $resolved = $repository->findOneByKey('sensor.unavailable.sensor.temp');
        self::assertInstanceOf(Alert::class, $resolved);
        self::assertFalse($resolved->isActive());
        self::assertEquals(self::at('2026-03-01 10:05'), $resolved->getResolvedAt());
        self::assertEquals(self::at('2026-03-01 10:01'), $resolved->getNotifiedAt());
        self::assertSame('ha_climate_sensor_temp', $resolved->getPersistentNotificationId());

        $resolved->reopen(self::at('2026-03-01 11:00'));
        $repository->save($resolved);
        $this->em()->clear();
        $reopened = $repository->findOneByKey('sensor.unavailable.sensor.temp');
        self::assertInstanceOf(Alert::class, $reopened);
        self::assertTrue($reopened->isActive());
        self::assertNull($reopened->getNotifiedAt());
        self::assertNull($reopened->getPersistentNotificationId());
        self::assertCount(2, $repository->findActive());
    }

    public function testAlertKeyIsUniqueAndDeletedWithItsRoom(): void
    {
        $room = new Room('Gone');
        $repository = $this->service(AlertRepository::class);
        $this->em()->persist($room);
        $repository->save(new Alert('k', 't', self::at('2026-03-01 10:00'), null, $room));
        $this->em()->clear();

        $loaded = $this->service(RoomRepository::class)->find($room->getId());
        self::assertInstanceOf(Room::class, $loaded);
        $this->service(RoomRepository::class)->remove($loaded);
        $this->em()->clear();
        self::assertNull($repository->findOneByKey('k'));

        $repository->save(new Alert('dup', 't', self::at('2026-03-01 10:00')));
        $this->expectException(UniqueConstraintViolationException::class);
        $repository->save(new Alert('dup', 't', self::at('2026-03-01 10:00')));
    }

    public function testCommandLogQueriesAndPurge(): void
    {
        $repository = $this->service(CommandLogRepository::class);
        $repository->save(new CommandLog('climate.a', self::at('2026-03-01 10:00'), 'climate.set_temperature', 'ctx-1', ['temperature' => 20.5], 20.5, HvacMode::Heat));
        $repository->save(new CommandLog('climate.a', self::at('2026-03-01 11:00'), 'climate.set_hvac_mode', 'ctx-2', ['hvac_mode' => 'off'], null, HvacMode::Off));
        $repository->save(new CommandLog('climate.b', self::at('2026-02-01 11:00'), 'climate.turn_off', 'ctx-3'));
        $this->em()->clear();

        $byContext = $repository->findOneByContextId('ctx-1');
        self::assertInstanceOf(CommandLog::class, $byContext);
        self::assertSame('climate.a', $byContext->getDeviceEntityId());
        self::assertSame(20.5, $byContext->getTargetTemperature());
        self::assertSame(HvacMode::Heat, $byContext->getHvacMode());
        self::assertSame(['temperature' => 20.5], $byContext->getData());
        self::assertSame('climate.set_temperature', $byContext->getService());
        self::assertSame('ctx-1', $byContext->getHaContextId());
        self::assertEquals(self::at('2026-03-01 10:00'), $byContext->getSentAt());
        self::assertNull($repository->findOneByContextId('ctx-unknown'));

        self::assertSame('ctx-2', $repository->findLastForDevice('climate.a')?->getHaContextId());
        self::assertNull($repository->findLastForDevice('climate.none'));

        self::assertSame(1, $repository->purgeOlderThan(self::at('2026-03-01 10:00')));
        $this->em()->clear();
        self::assertNull($repository->findOneByContextId('ctx-3'));
        self::assertNotNull($repository->findOneByContextId('ctx-1'));
    }

    public function testOverridesActiveWindow(): void
    {
        $room = new Room('Office');
        $other = new Room('Other');
        $this->persistAndClear($room, $other);
        $rooms = $this->service(RoomRepository::class);
        $room = $rooms->find($room->getId());
        $other = $rooms->find($other->getId());
        self::assertInstanceOf(Room::class, $room);
        self::assertInstanceOf(Room::class, $other);

        $repository = $this->service(RoomOverrideRepository::class);
        $repository->save(RoomOverride::boost($room, HvacMode::Heat, 2.0, self::at('2026-03-01 08:00'), self::at('2026-03-01 09:00'), false, 'user:alice'));
        $repository->save(RoomOverride::manual($room, HvacMode::Heat, 22.0, self::at('2026-03-01 08:30'), self::at('2026-03-01 10:30'), 'device'));
        $repository->save(RoomOverride::boost($other, HvacMode::Cool, -1.5, self::at('2026-03-01 08:00'), null, true, 'user:bob'));
        $this->em()->clear();

        $at = self::at('2026-03-01 08:45');
        $active = $repository->findActiveForRoom($room, $at);
        self::assertInstanceOf(RoomOverride::class, $active);
        self::assertSame(OverrideType::Manual, $active->getType());
        self::assertSame(22.0, $active->getAbsoluteTarget());
        self::assertNull($active->getDelta());
        self::assertSame('device', $active->getActor());
        self::assertSame(HvacMode::Heat, $active->getMode());
        self::assertFalse($active->isUntilNextSlot());
        self::assertTrue($active->isActiveAt($at));
        self::assertEquals(self::at('2026-03-01 10:30'), $active->getEndsAt());
        self::assertEquals(self::at('2026-03-01 08:30'), $active->getStartedAt());
        self::assertSame($room->getId()->toRfc4122(), $active->getRoom()->getId()->toRfc4122());

        $later = $repository->findActiveForRoom($room, self::at('2026-03-01 10:00'));
        self::assertSame(OverrideType::Manual, $later?->getType());
        self::assertNull($repository->findActiveForRoom($room, self::at('2026-03-01 07:00')));
        self::assertNull($repository->findActiveForRoom($room, self::at('2026-03-01 11:00')));

        $untilNextSlot = $repository->findActiveForRoom($other, self::at('2026-03-05 00:00'));
        self::assertInstanceOf(RoomOverride::class, $untilNextSlot);
        self::assertTrue($untilNextSlot->isUntilNextSlot());
        self::assertSame(-1.5, $untilNextSlot->getDelta());
        self::assertSame(OverrideType::Boost, $untilNextSlot->getType());
        self::assertCount(3, $repository->findAllActive(self::at('2026-03-01 08:45')));

        self::assertSame(2, $repository->purgeEndedBefore(self::at('2026-03-01 12:00')));
        $this->em()->clear();
        self::assertCount(1, $repository->findAllActive(self::at('2026-03-01 12:00')));
    }

    public function testHeatingRatePerRoomAndMode(): void
    {
        $room = new Room('Salon');
        $this->persistAndClear($room);
        $room = $this->service(RoomRepository::class)->find($room->getId());
        self::assertInstanceOf(Room::class, $room);

        $repository = $this->service(HeatingRateRepository::class);
        $repository->save(new HeatingRate($room, HvacMode::Heat, 1.5, self::at('2026-03-01 10:00')));
        $this->em()->clear();

        $rate = $repository->findOneByRoomAndMode($room, HvacMode::Heat);
        self::assertInstanceOf(HeatingRate::class, $rate);
        self::assertSame(1, $rate->getSamples());
        self::assertSame(1.5, $rate->getDegreesPerHour());
        self::assertSame(HvacMode::Heat, $rate->getMode());
        self::assertSame('Salon', $rate->getRoom()->getName());
        self::assertNull($repository->findOneByRoomAndMode($room, HvacMode::Cool));

        $rate->learn(1.8, self::at('2026-03-02 10:00'));
        $repository->save($rate);
        $this->em()->clear();
        $reloaded = $repository->findOneByRoomAndMode($room, HvacMode::Heat);
        self::assertInstanceOf(HeatingRate::class, $reloaded);
        self::assertSame(2, $reloaded->getSamples());
        self::assertSame(1.8, $reloaded->getDegreesPerHour());
        self::assertEquals(self::at('2026-03-02 10:00'), $reloaded->getUpdatedAt());

        $room = $this->service(RoomRepository::class)->find($room->getId());
        self::assertInstanceOf(Room::class, $room);
        $this->expectException(UniqueConstraintViolationException::class);
        $repository->save(new HeatingRate($room, HvacMode::Heat, 2.0, self::at('2026-03-03 10:00')));
    }

    public function testOverridesAndRatesAreRemovedWithTheirRoom(): void
    {
        $room = new Room('Doomed');
        $this->persistAndClear($room);
        $rooms = $this->service(RoomRepository::class);
        $room = $rooms->find($room->getId());
        self::assertInstanceOf(Room::class, $room);
        $this->service(RoomOverrideRepository::class)->save(RoomOverride::manual($room, HvacMode::Cool, 24.0, self::at('2026-03-01 08:00'), self::at('2026-03-01 09:00'), 'device'));
        $this->service(HeatingRateRepository::class)->save(new HeatingRate($room, HvacMode::Cool, 0.5, self::at('2026-03-01 08:00')));

        $rooms->remove($room);
        $this->em()->clear();

        self::assertSame(0, $this->em()->getRepository(RoomOverride::class)->count([]));
        self::assertSame(0, $this->em()->getRepository(HeatingRate::class)->count([]));
    }
}
