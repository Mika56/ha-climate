<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

use App\Domain\Preset\PresetKey;

/**
 * A schedule slot with its concrete start on a given date.
 */
final readonly class ResolvedSlot
{
    public function __construct(
        public ScheduleSlot $slot,
        public \DateTimeImmutable $start,
    ) {
    }

    public function presetKey(): PresetKey
    {
        return $this->slot->presetKey;
    }
}
