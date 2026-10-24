<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

use App\Domain\Preset\PresetKey;

final readonly class ScheduleSlot
{
    public function __construct(
        public Weekday $weekday,
        public SlotStart $start,
        public PresetKey $presetKey,
    ) {
    }
}
