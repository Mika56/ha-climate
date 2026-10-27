<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Support;

use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Domain\Climate\Temperature;
use App\Domain\Decision\GlobalPolicy;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\RoomEvaluationContext;
use App\Domain\Decision\RoomFacts;
use App\Domain\Device\AntiCyclingPolicy;
use App\Domain\Device\Device;
use App\Domain\Device\DeviceRole;
use App\Domain\HomeAssistant\EntityId;
use App\Domain\Preset\PresetKey;
use App\Domain\Room\PresenceSignal;
use App\Domain\Room\PresenceSignalKind;
use App\Domain\Room\PresetTemperatures;
use App\Domain\Room\RoomId;
use App\Domain\Schedule\FixedSlotStart;
use App\Domain\Schedule\ScheduleId;
use App\Domain\Schedule\ScheduleSlot;
use App\Domain\Schedule\SlotStart;
use App\Domain\Schedule\TimeAnchorResolverInterface;
use App\Domain\Schedule\Weekday;
use App\Domain\Schedule\WeeklySchedule;

/**
 * Builders shared by the domain tests.
 */
final class Fixtures
{
    private function __construct()
    {
    }

    public static function at(string $dateTime, string $timezone = 'Europe/Paris'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($dateTime, new \DateTimeZone($timezone));
    }

    public static function entity(string $id = 'climate.living'): EntityId
    {
        return new EntityId($id);
    }

    public static function preset(string $key): PresetKey
    {
        return new PresetKey($key);
    }

    public static function temperature(float $celsius): Temperature
    {
        return new Temperature($celsius);
    }

    public static function device(DeviceRole $role = DeviceRole::Heating, string $entity = 'climate.living'): Device
    {
        return new Device(self::entity($entity), $role, new AntiCyclingPolicy(300, 300, 0.5));
    }

    public static function temperatures(): PresetTemperatures
    {
        return PresetTemperatures::empty()
            ->with(self::preset('comfort'), HvacMode::Heat, self::temperature(21.0))
            ->with(self::preset('eco'), HvacMode::Heat, self::temperature(18.0))
            ->with(self::preset('night'), HvacMode::Heat, self::temperature(17.0))
            ->with(self::preset('comfort'), HvacMode::Cool, self::temperature(24.0))
            ->with(self::preset('eco'), HvacMode::Cool, self::temperature(27.0))
            ->with(self::preset('night'), HvacMode::Cool, self::temperature(26.0));
    }

    /**
     * @param list<Device>|null $devices
     */
    public static function room(?array $devices = null, ?PresetTemperatures $temperatures = null): RoomConfiguration
    {
        return new RoomConfiguration(
            new RoomId('living'),
            'Living room',
            $devices ?? [self::device(DeviceRole::Reversible)],
            $temperatures ?? self::temperatures(),
            new ScheduleId('heat-week'),
            new ScheduleId('cool-week'),
            self::entity('sensor.living_temperature'),
            [new PresenceSignal(self::entity('binary_sensor.living_presence'), PresenceSignalKind::Active)],
            [self::entity('binary_sensor.living_window')],
            600,
            120,
            1800,
        );
    }

    public static function slot(Weekday $day, SlotStart $start, string $preset): ScheduleSlot
    {
        return new ScheduleSlot($day, $start, self::preset($preset));
    }

    public static function fixed(int $hour, int $minute = 0): FixedSlotStart
    {
        return new FixedSlotStart($hour * 60 + $minute);
    }

    /**
     * Mon-Fri 06:30 comfort / 08:30 eco / 17:00 comfort / 22:30 night, Sat-Sun 08:00 comfort / 23:00 night.
     */
    public static function weeklySchedule(HvacMode $mode = HvacMode::Heat): WeeklySchedule
    {
        $slots = [];

        foreach ([Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday] as $day) {
            $slots[] = self::slot($day, self::fixed(6, 30), 'comfort');
            $slots[] = self::slot($day, self::fixed(8, 30), 'eco');
            $slots[] = self::slot($day, self::fixed(17), 'comfort');
            $slots[] = self::slot($day, self::fixed(22, 30), 'night');
        }

        foreach ([Weekday::Saturday, Weekday::Sunday] as $day) {
            $slots[] = self::slot($day, self::fixed(8), 'comfort');
            $slots[] = self::slot($day, self::fixed(23), 'night');
        }

        return new WeeklySchedule(new ScheduleId('week'), 'Week', $mode, $slots);
    }

    public static function context(
        Season $season = Season::Winter,
        ?RoomConfiguration $room = null,
        ?WeeklySchedule $schedule = null,
        ?\DateTimeImmutable $now = null,
        ?TimeAnchorResolverInterface $anchors = null,
    ): RoomEvaluationContext {
        return new RoomEvaluationContext(
            $now ?? self::at('2026-10-26 07:00'),
            $season,
            $room ?? self::room(),
            RoomFacts::none(),
            $schedule,
            $anchors ?? new ArrayAnchorResolver(),
            new GlobalPolicy(1800, self::preset('eco'), self::preset('away')),
        );
    }
}
