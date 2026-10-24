<?php

declare(strict_types=1);

namespace App\Domain\Decision;

use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Domain\Schedule\TimeAnchorResolverInterface;
use App\Domain\Schedule\WeeklySchedule;

/**
 * Everything a rule needs to evaluate one room at one instant.
 */
final readonly class RoomEvaluationContext
{
    /**
     * @param WeeklySchedule|null $schedule the schedule already chosen for the active season
     */
    public function __construct(
        public \DateTimeImmutable $now,
        public Season $season,
        public RoomConfiguration $room,
        public RoomFacts $facts,
        public ?WeeklySchedule $schedule,
        public TimeAnchorResolverInterface $anchors,
        public GlobalPolicy $policy,
    ) {
    }

    public function mode(): HvacMode
    {
        return $this->season->activeHvacMode();
    }
}
