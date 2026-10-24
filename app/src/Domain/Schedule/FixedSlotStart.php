<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

final readonly class FixedSlotStart implements SlotStart
{
    /** @var int<0, 1439> */
    private int $minuteOfDay;

    public function __construct(int $minuteOfDay)
    {
        $this->minuteOfDay = MinuteOfDay::assert($minuteOfDay);
    }

    public function minuteOfDay(TimeAnchorResolverInterface $anchors): int
    {
        return $this->minuteOfDay;
    }
}
