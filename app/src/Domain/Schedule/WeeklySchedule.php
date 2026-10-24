<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

use App\Domain\Climate\HvacMode;
use App\Domain\Exception\InvalidValueException;

/**
 * Weekly list of slots for one HVAC mode. Slot times are wall-clock local times
 * in the timezone of the instant being evaluated.
 */
final readonly class WeeklySchedule
{
    /**
     * @param list<ScheduleSlot> $slots
     */
    public function __construct(
        public ScheduleId $id,
        public string $name,
        public HvacMode $mode,
        public array $slots,
    ) {
        if (HvacMode::Off === $mode) {
            throw new InvalidValueException('A schedule must target heat or cool mode.');
        }

        if ([] === $slots) {
            throw new InvalidValueException('A schedule needs at least one slot.');
        }
    }

    /**
     * The slot in force at the given instant: the latest one started so far,
     * looking back up to one week.
     */
    public function activeSlotAt(\DateTimeImmutable $at, TimeAnchorResolverInterface $anchors): ResolvedSlot
    {
        $today = $at->setTime(0, 0);

        for ($back = 0; $back <= 6; ++$back) {
            $day = 0 === $back ? $today : $today->modify(\sprintf('-%d days', $back));
            $started = array_values(array_filter(
                $this->slotsOn($day, $anchors),
                static fn (ResolvedSlot $slot): bool => $slot->start <= $at,
            ));

            if ([] !== $started) {
                return $started[\count($started) - 1];
            }
        }

        // Same weekday one week ago: every slot of that day is in the past.
        $lastWeek = $this->slotsOn($today->modify('-7 days'), $anchors);

        return $lastWeek[\count($lastWeek) - 1] ?? throw new \LogicException('Unreachable: a schedule always has a slot.');
    }

    /**
     * The first slot starting strictly after the given instant.
     */
    public function nextSlotAfter(\DateTimeImmutable $at, TimeAnchorResolverInterface $anchors): ResolvedSlot
    {
        $today = $at->setTime(0, 0);

        for ($ahead = 0; $ahead <= 6; ++$ahead) {
            $day = 0 === $ahead ? $today : $today->modify(\sprintf('+%d days', $ahead));
            $upcoming = array_values(array_filter(
                $this->slotsOn($day, $anchors),
                static fn (ResolvedSlot $slot): bool => $slot->start > $at,
            ));

            if ([] !== $upcoming) {
                return $upcoming[0];
            }
        }

        // Same weekday one week ahead: every slot of that day is in the future.
        return $this->slotsOn($today->modify('+7 days'), $anchors)[0]
            ?? throw new \LogicException('Unreachable: a schedule always has a slot.');
    }

    /**
     * Slots of the weekday of $day, ordered by resolved start (ties keep declaration order).
     *
     * @return list<ResolvedSlot>
     */
    private function slotsOn(\DateTimeImmutable $day, TimeAnchorResolverInterface $anchors): array
    {
        $weekday = Weekday::fromDate($day);
        $entries = [];

        foreach ($this->slots as $slot) {
            if ($slot->weekday === $weekday) {
                $entries[] = [$slot->start->minuteOfDay($anchors), $slot];
            }
        }

        usort($entries, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(
            static fn (array $entry): ResolvedSlot => new ResolvedSlot(
                $entry[1],
                $day->setTime(intdiv($entry[0], 60), $entry[0] % 60),
            ),
            $entries,
        );
    }
}
