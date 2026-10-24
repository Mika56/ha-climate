<?php

declare(strict_types=1);

namespace App\Application\Engine\Heating;

use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Temperature;

/**
 * One stretch of a room being driven towards a target, from the temperature it started at.
 */
final readonly class HeatingEpisode
{
    public function __construct(
        public HvacMode $mode,
        public Temperature $target,
        public Temperature $startTemperature,
        public \DateTimeImmutable $startedAt,
    ) {
    }

    public function isReachedBy(Temperature $current): bool
    {
        return HvacMode::Heat === $this->mode ? $current->isAtLeast($this->target) : $current->isAtMost($this->target);
    }

    /**
     * Degrees travelled so far, as a positive number.
     */
    public function degreesTravelled(Temperature $current): float
    {
        return abs($current->toFloat() - $this->startTemperature->toFloat());
    }
}
