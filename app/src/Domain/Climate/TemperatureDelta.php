<?php

declare(strict_types=1);

namespace App\Domain\Climate;

use App\Domain\Exception\InvalidValueException;

/**
 * A strictly positive temperature difference in °C (for instance a boost).
 */
final readonly class TemperatureDelta
{
    public const float MIN = 0.5;
    public const float MAX = 10.0;

    private float $celsius;

    public function __construct(float $celsius)
    {
        $rounded = round($celsius, 1);

        if (!is_finite($celsius) || $rounded < self::MIN || $rounded > self::MAX) {
            throw new InvalidValueException(\sprintf('Temperature delta must be between %.1f and %.1f °C, got %s.', self::MIN, self::MAX, (string) $celsius));
        }

        $this->celsius = $rounded;
    }

    public function toFloat(): float
    {
        return $this->celsius;
    }
}
