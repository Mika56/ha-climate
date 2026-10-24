<?php

declare(strict_types=1);

namespace App\Domain\Decision;

use App\Domain\Exception\InvalidValueException;
use App\Domain\Preset\PresetKey;

final readonly class GlobalPolicy
{
    public function __construct(
        public int $houseEmptyDelaySeconds,
        public PresetKey $vacancyPreset,
        public PresetKey $absentPreset,
    ) {
        if ($houseEmptyDelaySeconds < 0) {
            throw new InvalidValueException('House empty delay must not be negative.');
        }
    }
}
