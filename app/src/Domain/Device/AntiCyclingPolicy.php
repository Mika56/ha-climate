<?php

declare(strict_types=1);

namespace App\Domain\Device;

use App\Domain\Exception\InvalidValueException;

/**
 * Short-cycling protection settings of a device.
 */
final readonly class AntiCyclingPolicy
{
    public const float MAX_HYSTERESIS = 2.0;

    public function __construct(
        public int $minOnSeconds,
        public int $minOffSeconds,
        public float $hysteresis,
    ) {
        if ($minOnSeconds < 0 || $minOffSeconds < 0) {
            throw new InvalidValueException('Minimum on/off durations must not be negative.');
        }

        if (!is_finite($hysteresis) || $hysteresis < 0.0 || $hysteresis > self::MAX_HYSTERESIS) {
            throw new InvalidValueException(\sprintf('Hysteresis must be between 0 and %.1f °C.', self::MAX_HYSTERESIS));
        }
    }
}
