<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Support;

use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Domain\Decision\GlobalPolicy;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\RoomEvaluationContext;
use App\Domain\Decision\RoomFacts;
use App\Domain\Device\DeviceRole;
use App\Domain\Room\PresetTemperatures;
use App\Domain\Room\RoomId;
use App\Domain\Schedule\ScheduleId;
use App\Domain\Schedule\WeeklySchedule;

/**
 * Evaluation contexts with facts, preheat settings and the full set of presets (including `away`), for the
 * presence, vacation and preheat rules.
 */
final class Contexts
{
    private function __construct()
    {
    }

    /**
     * Heat: comfort 21, eco 18, night 17, away 15. Cool: comfort 24, eco 27, night 26, away 30.
     */
    public static function temperatures(): PresetTemperatures
    {
        $temperatures = Fixtures::temperatures();
        $temperatures = $temperatures->with(Fixtures::preset('away'), HvacMode::Heat, Fixtures::temperature(15.0));

        return $temperatures->with(Fixtures::preset('away'), HvacMode::Cool, Fixtures::temperature(30.0));
    }

    public static function room(bool $preheat = false, int $preheatMaxMinutes = 120, int $vacancyDelay = 600): RoomConfiguration
    {
        return new RoomConfiguration(
            new RoomId('living'),
            'Living room',
            [Fixtures::device(DeviceRole::Reversible)],
            self::temperatures(),
            new ScheduleId('heat-week'),
            new ScheduleId('cool-week'),
            Fixtures::entity('sensor.living_temperature'),
            Fixtures::entity('binary_sensor.living_presence'),
            [],
            $vacancyDelay,
            120,
            1800,
            $preheat,
            $preheatMaxMinutes,
        );
    }

    public static function make(
        string $now,
        Season $season = Season::Winter,
        ?RoomFacts $facts = null,
        ?RoomConfiguration $room = null,
        ?WeeklySchedule $schedule = null,
        int $houseEmptyDelaySeconds = 1800,
    ): RoomEvaluationContext {
        return new RoomEvaluationContext(
            Fixtures::at($now),
            $season,
            $room ?? self::room(),
            $facts ?? RoomFacts::none(),
            $schedule ?? Fixtures::weeklySchedule(Season::Summer === $season ? HvacMode::Cool : HvacMode::Heat),
            new ArrayAnchorResolver(),
            new GlobalPolicy($houseEmptyDelaySeconds, Fixtures::preset('eco'), Fixtures::preset('away')),
        );
    }
}
