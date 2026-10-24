<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\Entity;

use App\Domain\Climate\HvacMode;
use App\Infrastructure\Persistence\Entity\Device;
use App\Infrastructure\Persistence\Entity\Preset;
use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Entity\RoomPresetTemperature;
use App\Infrastructure\Persistence\Entity\Schedule;
use App\Infrastructure\Persistence\Enum\DeviceRole;
use App\Infrastructure\Persistence\Support\Guard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Room::class)]
#[CoversClass(Device::class)]
#[CoversClass(RoomPresetTemperature::class)]
#[CoversClass(Preset::class)]
#[UsesClass(Schedule::class)]
#[UsesClass(Guard::class)]
final class RoomTest extends TestCase
{
    public function testDefaults(): void
    {
        $room = new Room('Bedroom', 2);

        self::assertSame('Bedroom', $room->getName());
        self::assertSame(2, $room->getPosition());
        self::assertNull($room->getHaAreaId());
        self::assertNull($room->getTemperatureSensor());
        self::assertNull($room->getPresenceSensor());
        self::assertSame([], $room->getOpenings());
        self::assertFalse($room->isPreheatEnabled());
        self::assertCount(0, $room->getDevices());
        self::assertCount(0, $room->getPresetTemperatures());
        self::assertNull($room->getHeatingSchedule());
        self::assertNull($room->getCoolingSchedule());
        self::assertNull($room->getScheduleFor(HvacMode::Off));
    }

    public function testMutators(): void
    {
        $room = new Room('Bedroom');
        $room->rename('Master bedroom');
        $room->moveTo(5);
        $room->linkToArea(null);

        self::assertSame('Master bedroom', $room->getName());
        self::assertSame(5, $room->getPosition());
        self::assertNull($room->getHaAreaId());
    }

    public function testRejectsBlankName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Room('  ');
    }

    public function testRejectsNegativeDelays(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Room('A'))->configureDelays(-1, 0, 0);
    }

    public function testRejectsBlankOpening(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Room('A'))->configureSensors(null, null, ['']);
    }

    public function testScheduleModeMustMatch(): void
    {
        $room = new Room('A');

        $this->expectException(\InvalidArgumentException::class);
        $room->assignSchedule(HvacMode::Heat, new Schedule('Summer', HvacMode::Cool));
    }

    public function testOffIsNotAConfigurableScheduleMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Room('A'))->assignSchedule(HvacMode::Off, null);
    }

    public function testScheduleCanBeCleared(): void
    {
        $room = new Room('A');
        $room->assignSchedule(HvacMode::Cool, new Schedule('Summer', HvacMode::Cool));
        $room->assignSchedule(HvacMode::Cool, null);

        self::assertNull($room->getCoolingSchedule());
    }

    public function testDeviceInvariants(): void
    {
        $room = new Room('A');
        $device = $room->addDevice('climate.a', DeviceRole::Heating, 60, 30, 0.2);

        self::assertSame($room, $device->getRoom());
        self::assertSame(30, $device->getMinOffSeconds());
        self::assertNotEmpty($device->getId()->toRfc4122());

        $device->changeRole(DeviceRole::Reversible);
        $device->configureAntiCycling(10, 20, 1.0);
        self::assertSame(DeviceRole::Reversible, $device->getRole());
        self::assertSame(10, $device->getMinOnSeconds());
        self::assertSame(1.0, $device->getHysteresis());
    }

    /**
     * @return iterable<string, array{string, int, int, float}>
     */
    public static function invalidDevices(): iterable
    {
        yield 'blank entity id' => [' ', 0, 0, 0.5];
        yield 'negative min on' => ['climate.a', -1, 0, 0.5];
        yield 'negative min off' => ['climate.a', 0, -1, 0.5];
        yield 'negative hysteresis' => ['climate.a', 0, 0, -0.1];
    }

    #[DataProvider('invalidDevices')]
    public function testRejectsInvalidDevices(string $entityId, int $minOn, int $minOff, float $hysteresis): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Room('A'))->addDevice($entityId, DeviceRole::Heating, $minOn, $minOff, $hysteresis);
    }

    public function testRejectsNegativeDeviceAntiCycling(): void
    {
        $device = (new Room('A'))->addDevice('climate.a', DeviceRole::Heating);

        $this->expectException(\InvalidArgumentException::class);
        $device->configureAntiCycling(0, 0, -1.0);
    }

    public function testPresetTemperatureRejectsOffMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Room('A'))->setPresetTemperature(new Preset('eco', 'Eco', '#00ff00'), HvacMode::Off, 17.0);
    }

    public function testPresetTemperatureAccessors(): void
    {
        $room = new Room('A');
        $preset = new Preset('eco', 'Eco', '#00ff00');
        $temperature = $room->setPresetTemperature($preset, HvacMode::Cool, 26.0);

        self::assertSame($room, $temperature->getRoom());
        self::assertSame($preset, $temperature->getPreset());
        self::assertSame(HvacMode::Cool, $temperature->getMode());
        self::assertSame(26.0, $temperature->getTemperature());
        self::assertNotEmpty($temperature->getId()->toRfc4122());

        $room->removePresetTemperature($temperature);
        self::assertCount(0, $room->getPresetTemperatures());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidPresets(): iterable
    {
        yield 'uppercase key' => ['Comfort', '#ffffff'];
        yield 'spaces in key' => ['my preset', '#ffffff'];
        yield 'short color' => ['comfort', '#fff'];
        yield 'named color' => ['comfort', 'red'];
    }

    #[DataProvider('invalidPresets')]
    public function testRejectsInvalidPresets(string $key, string $color): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Preset($key, 'Label', $color);
    }

    public function testPresetMutators(): void
    {
        $preset = new Preset('comfort', 'Comfort', '#ff9800', 1);
        $preset->describe('Cosy', '#FF0000');
        $preset->moveTo(4);

        self::assertSame('comfort', $preset->getKey());
        self::assertSame('Cosy', $preset->getLabel());
        self::assertSame('#FF0000', $preset->getColor());
        self::assertSame(4, $preset->getPosition());
        self::assertNotEmpty($preset->getId()->toRfc4122());
    }
}
