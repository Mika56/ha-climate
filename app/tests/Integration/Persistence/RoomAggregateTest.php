<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Domain\Climate\HvacMode;
use App\Domain\Device\DeviceRole;
use App\Domain\HomeAssistant\EntityId;
use App\Domain\Room\PresenceSignal;
use App\Domain\Room\PresenceSignalKind;
use App\Infrastructure\Persistence\Entity\Device;
use App\Infrastructure\Persistence\Entity\Preset;
use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Entity\RoomPresetTemperature;
use App\Infrastructure\Persistence\Entity\Schedule;
use App\Infrastructure\Persistence\Repository\DeviceRepository;
use App\Infrastructure\Persistence\Repository\PresetRepository;
use App\Infrastructure\Persistence\Repository\RoomRepository;
use App\Infrastructure\Persistence\Repository\ScheduleRepository;
use App\Infrastructure\Persistence\Sqlite\SqlitePragmaDriver;
use App\Infrastructure\Persistence\Sqlite\SqlitePragmaMiddleware;
use App\Infrastructure\Persistence\Support\Guard;
use App\Infrastructure\Persistence\Type\UtcDateTimeImmutableType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(RoomRepository::class)]
#[CoversClass(DeviceRepository::class)]
#[CoversClass(PresetRepository::class)]
#[CoversClass(ScheduleRepository::class)]
#[CoversClass(Room::class)]
#[CoversClass(Device::class)]
#[CoversClass(Preset::class)]
#[CoversClass(RoomPresetTemperature::class)]
#[CoversClass(Schedule::class)]
#[CoversClass(Guard::class)]
#[CoversClass(SqlitePragmaMiddleware::class)]
#[CoversClass(SqlitePragmaDriver::class)]
#[CoversClass(UtcDateTimeImmutableType::class)]
#[UsesClass(PresenceSignal::class)]
#[UsesClass(EntityId::class)]
final class RoomAggregateTest extends PersistenceTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testRoomsAreListedByPositionThenName(): void
    {
        $repository = $this->service(RoomRepository::class);
        $repository->save(new Room('Study', 2));
        $repository->save(new Room('Bedroom', 1));
        $repository->save(new Room('Attic', 1));
        $this->em()->clear();

        self::assertSame(['Attic', 'Bedroom', 'Study'], array_map(
            static fn (Room $room): string => $room->getName(),
            $repository->findAllOrdered(),
        ));
    }

    public function testRoomRoundTripKeepsConfiguration(): void
    {
        $room = new Room('Living room', 3);
        $room->linkToArea('living_room');
        $room->configureSensors('sensor.living_temp', [new PresenceSignal(new EntityId('binary_sensor.living_presence'), PresenceSignalKind::Active), new PresenceSignal(new EntityId('sensor.tv_power'), PresenceSignalKind::Above, 10.0)], ['binary_sensor.window', 'binary_sensor.door', 'binary_sensor.window']);
        $room->configureDelays(600, 90, 1200);
        $room->configurePreheat(true, 45);
        $this->persistAndClear($room);

        $loaded = $this->service(RoomRepository::class)->find($room->getId());

        self::assertInstanceOf(Room::class, $loaded);
        self::assertSame('living_room', $loaded->getHaAreaId());
        self::assertSame('sensor.living_temp', $loaded->getTemperatureSensor());
        self::assertEquals([new PresenceSignal(new EntityId('binary_sensor.living_presence'), PresenceSignalKind::Active), new PresenceSignal(new EntityId('sensor.tv_power'), PresenceSignalKind::Above, 10.0)], $loaded->getPresenceSignals());
        self::assertSame(['binary_sensor.window', 'binary_sensor.door'], $loaded->getOpenings());
        self::assertSame(600, $loaded->getVacancyDelaySeconds());
        self::assertSame(90, $loaded->getWindowCutDelaySeconds());
        self::assertSame(1200, $loaded->getWindowNotifyDelaySeconds());
        self::assertTrue($loaded->isPreheatEnabled());
        self::assertSame(45, $loaded->getPreheatMaxMinutes());
        self::assertSame(3, $loaded->getPosition());
    }

    public function testDevicesAreCascadedAndOrphansRemoved(): void
    {
        $room = new Room('Bedroom');
        $room->addDevice('climate.bedroom', DeviceRole::Reversible, 300, 180, 0.3);
        $room->addDevice('climate.bedroom_floor', DeviceRole::Heating);
        $this->persistAndClear($room);

        $repository = $this->service(RoomRepository::class);
        $loaded = $repository->find($room->getId());
        self::assertInstanceOf(Room::class, $loaded);
        self::assertCount(2, $loaded->getDevices());

        $device = $this->service(DeviceRepository::class)->findOneByEntityId('climate.bedroom');
        self::assertInstanceOf(Device::class, $device);
        self::assertSame(DeviceRole::Reversible, $device->getRole());
        self::assertSame(300, $device->getMinOnSeconds());
        self::assertSame(0.3, $device->getHysteresis());

        $loaded->removeDevice($device);
        $this->em()->flush();
        $this->em()->clear();

        self::assertNull($this->service(DeviceRepository::class)->findOneByEntityId('climate.bedroom'));
        self::assertNotNull($this->service(DeviceRepository::class)->findOneByEntityId('climate.bedroom_floor'));
    }

    public function testDeletingARoomCascadesToItsDevicesAndTemperatures(): void
    {
        $presets = $this->service(PresetRepository::class);
        $comfort = new Preset('comfort', 'Comfort', '#ff9800');
        $presets->save($comfort);
        $room = new Room('Office');
        $room->addDevice('climate.office', DeviceRole::Heating);
        $room->setPresetTemperature($comfort, HvacMode::Heat, 20.5);
        $this->persistAndClear($room);

        $repository = $this->service(RoomRepository::class);
        $loaded = $repository->find($room->getId());
        self::assertInstanceOf(Room::class, $loaded);
        $repository->remove($loaded);
        $this->em()->clear();

        self::assertNull($this->service(DeviceRepository::class)->findOneByEntityId('climate.office'));
        self::assertSame(0, $this->em()->getRepository(RoomPresetTemperature::class)->count([]));
    }

    public function testDeviceEntityIdIsUnique(): void
    {
        $first = new Room('A');
        $first->addDevice('climate.same', DeviceRole::Heating);
        $second = new Room('B');
        $second->addDevice('climate.same', DeviceRole::Cooling);

        $this->em()->persist($first);
        $this->em()->persist($second);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em()->flush();
    }

    public function testPresetTemperatureIsUpsertedPerPresetAndMode(): void
    {
        $comfort = new Preset('comfort', 'Comfort', '#ff9800');
        $eco = new Preset('eco', 'Eco', '#4caf50', 1);
        $room = new Room('Kids');
        $heat = $room->setPresetTemperature($comfort, HvacMode::Heat, 20.0);
        $again = $room->setPresetTemperature($comfort, HvacMode::Heat, 21.0);
        $room->setPresetTemperature($comfort, HvacMode::Cool, 25.0);
        $room->setPresetTemperature($eco, HvacMode::Heat, 17.0);
        $this->persistAndClear($comfort, $eco, $room);

        self::assertSame($heat, $again);

        $loaded = $this->service(RoomRepository::class)->find($room->getId());
        self::assertInstanceOf(Room::class, $loaded);
        self::assertCount(3, $loaded->getPresetTemperatures());

        $temperatures = [];
        foreach ($loaded->getPresetTemperatures() as $temperature) {
            $temperatures[$temperature->getPreset()->getKey().'/'.$temperature->getMode()->value] = $temperature->getTemperature();
        }
        ksort($temperatures);
        self::assertSame(['comfort/cool' => 25.0, 'comfort/heat' => 21.0, 'eco/heat' => 17.0], $temperatures);

        $first = $loaded->getPresetTemperatures()->first();
        self::assertInstanceOf(RoomPresetTemperature::class, $first);
        $loaded->removePresetTemperature($first);
        $this->em()->flush();
        $this->em()->clear();

        $reloaded = $this->service(RoomRepository::class)->find($room->getId());
        self::assertInstanceOf(Room::class, $reloaded);
        self::assertCount(2, $reloaded->getPresetTemperatures());
    }

    public function testDuplicateTemperatureRowIsRejectedByTheDatabase(): void
    {
        $comfort = new Preset('comfort', 'Comfort', '#ff9800');
        $room = new Room('Dup');
        $this->persistAndClear($comfort, $room);

        $room = $this->service(RoomRepository::class)->find($room->getId());
        $comfort = $this->service(PresetRepository::class)->findOneByKey('comfort');
        self::assertInstanceOf(Room::class, $room);
        self::assertInstanceOf(Preset::class, $comfort);
        $this->em()->persist(new RoomPresetTemperature($room, $comfort, HvacMode::Heat, 20.0));
        $this->em()->persist(new RoomPresetTemperature($room, $comfort, HvacMode::Heat, 21.0));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em()->flush();
    }

    public function testSchedulesAreAssignedAndDetachedWhenDeleted(): void
    {
        $heating = new Schedule('Winter week', HvacMode::Heat);
        $cooling = new Schedule('Summer week', HvacMode::Cool);
        $room = new Room('Salon');
        $room->assignSchedule(HvacMode::Heat, $heating);
        $room->assignSchedule(HvacMode::Cool, $cooling);
        $this->persistAndClear($heating, $cooling, $room);

        $loaded = $this->service(RoomRepository::class)->find($room->getId());
        self::assertInstanceOf(Room::class, $loaded);
        self::assertSame('Winter week', $loaded->getHeatingSchedule()?->getName());
        self::assertSame('Summer week', $loaded->getScheduleFor(HvacMode::Cool)?->getName());

        $schedules = $this->service(ScheduleRepository::class);
        self::assertSame(['Summer week', 'Winter week'], array_map(
            static fn (Schedule $schedule): string => $schedule->getName(),
            $schedules->findAllOrdered(),
        ));

        $schedule = $loaded->getHeatingSchedule();
        $schedules->remove($schedule);
        $this->em()->clear();

        $reloaded = $this->service(RoomRepository::class)->find($room->getId());
        self::assertInstanceOf(Room::class, $reloaded);
        self::assertNull($reloaded->getHeatingSchedule());
        self::assertNotNull($reloaded->getCoolingSchedule());
    }
}
