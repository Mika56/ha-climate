<?php

declare(strict_types=1);

namespace App\Application\Engine\Facts;

use App\Application\Engine\EngineSnapshot;
use App\Application\Engine\Heating\HeatingRateLearner;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\RoomFacts;

/**
 * The learned speed of the room (°C per hour) in the active mode.
 *
 * Deliberate departure from the "pure contributor" guideline: contributors run on every evaluation of the
 * room, which makes this the hook that feeds the learner with what the devices and the sensor report now,
 * without touching the evaluator. See {@see HeatingRateLearner}.
 */
final readonly class HeatingRateContributor implements RoomFactContributorInterface
{
    public function __construct(private HeatingRateLearner $learner)
    {
    }

    public function contribute(RoomConfiguration $room, RoomFacts $facts, EngineSnapshot $snapshot): RoomFacts
    {
        $this->learner->observe($room, $snapshot);

        $mode = $snapshot->configuration->season->activeHvacMode();

        return $facts->withHeatingRatePerHour($this->learner->rate($room, $mode));
    }
}
