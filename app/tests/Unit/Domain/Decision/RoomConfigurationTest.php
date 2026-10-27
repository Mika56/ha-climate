<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Decision;

use App\Domain\Climate\HvacMode;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Device\DeviceRole;
use App\Domain\Exception\InvalidValueException;
use App\Domain\Room\PresetTemperatures;
use App\Domain\Room\RoomId;
use App\Domain\Schedule\ScheduleId;
use App\Tests\Unit\Domain\Support\Contexts;
use App\Tests\Unit\Domain\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoomConfiguration::class)]
#[UsesClass(\App\Domain\Climate\Temperature::class)]
#[UsesClass(\App\Domain\Device\AntiCyclingPolicy::class)]
#[UsesClass(\App\Domain\Device\Device::class)]
#[UsesClass(DeviceRole::class)]
#[UsesClass(\App\Domain\HomeAssistant\EntityId::class)]
#[UsesClass(\App\Domain\Preset\PresetKey::class)]
#[UsesClass(PresetTemperatures::class)]
#[UsesClass(RoomId::class)]
#[UsesClass(\App\Domain\Room\PresenceSignal::class)]
#[UsesClass(ScheduleId::class)]
final class RoomConfigurationTest extends TestCase
{
    /**
     * @param list<DeviceRole> $roles
     */
    #[DataProvider('provideDeviceSets')]
    public function testHasDeviceFor(array $roles, HvacMode $mode, bool $expected): void
    {
        $devices = array_map(static fn (DeviceRole $role) => Fixtures::device($role), $roles);

        self::assertSame($expected, Fixtures::room($devices)->hasDeviceFor($mode));
    }

    /**
     * @return iterable<string, array{list<DeviceRole>, HvacMode, bool}>
     */
    public static function provideDeviceSets(): iterable
    {
        yield 'no devices' => [[], HvacMode::Heat, false];
        yield 'heater for heat' => [[DeviceRole::Heating], HvacMode::Heat, true];
        yield 'heater for cool' => [[DeviceRole::Heating], HvacMode::Cool, false];
        yield 'mixed devices for cool' => [[DeviceRole::Heating, DeviceRole::Cooling], HvacMode::Cool, true];
        yield 'reversible for cool' => [[DeviceRole::Reversible], HvacMode::Cool, true];
        yield 'anything for off' => [[DeviceRole::Reversible], HvacMode::Off, false];
    }

    public function testScheduleIdForMode(): void
    {
        $room = Fixtures::room();

        self::assertSame('heat-week', (string) $room->scheduleIdFor(HvacMode::Heat));
        self::assertSame('cool-week', (string) $room->scheduleIdFor(HvacMode::Cool));
        self::assertNull($room->scheduleIdFor(HvacMode::Off));
    }

    public function testBlankNameIsRejected(): void
    {
        $this->expectException(InvalidValueException::class);

        $this->build(' ', 0, 0, 0);
    }

    #[DataProvider('provideNegativeDelays')]
    public function testNegativeDelaysAreRejected(int $vacancy, int $cut, int $notify): void
    {
        $this->expectException(InvalidValueException::class);

        $this->build('Room', $vacancy, $cut, $notify);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function provideNegativeDelays(): iterable
    {
        yield 'vacancy' => [-1, 0, 0];
        yield 'window cut' => [0, -1, 0];
        yield 'window notify' => [0, 0, -1];
    }

    public function testZeroDelaysAreAccepted(): void
    {
        $room = $this->build('Room', 0, 0, 0);

        self::assertSame(0, $room->vacancyDelay);
        self::assertSame(0, $room->windowCutDelay);
        self::assertSame(0, $room->windowNotifyDelay);
    }

    public function testPreheatDefaultsToDisabledWithTwoHoursAtMost(): void
    {
        $room = $this->build('Room', 0, 0, 0);

        self::assertFalse($room->preheatEnabled);
        self::assertSame(120, $room->preheatMaxMinutes);
    }

    public function testPreheatSettingsAreKeptAndAZeroMaximumAccepted(): void
    {
        $room = Contexts::room(true, 0);

        self::assertTrue($room->preheatEnabled);
        self::assertSame(0, $room->preheatMaxMinutes);
    }

    public function testNegativePreheatMaximumIsRejected(): void
    {
        $this->expectException(InvalidValueException::class);

        new RoomConfiguration(new RoomId('room'), 'Room', [], PresetTemperatures::empty(), null, null, null, [], [], 0, 0, 0, true, -1);
    }

    private function build(string $name, int $vacancy, int $cut, int $notify): RoomConfiguration
    {
        return new RoomConfiguration(
            new RoomId('room'),
            $name,
            [],
            PresetTemperatures::empty(),
            null,
            new ScheduleId('cool'),
            null,
            [],
            [],
            $vacancy,
            $cut,
            $notify,
        );
    }
}
