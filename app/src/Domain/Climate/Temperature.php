<?php

declare(strict_types=1);

namespace App\Domain\Climate;

use App\Domain\Exception\InvalidValueException;

/**
 * A target or measured room temperature in degrees Celsius, rounded to 0.1.
 */
final readonly class Temperature
{
    public const float MIN = 5.0;
    public const float MAX = 35.0;

    private float $celsius;

    public function __construct(float $celsius)
    {
        $rounded = round($celsius, 1);

        if (!is_finite($celsius) || $rounded < self::MIN || $rounded > self::MAX) {
            throw new InvalidValueException(\sprintf('Temperature must be between %.1f and %.1f °C, got %s.', self::MIN, self::MAX, var_export($celsius, true)));
        }

        $this->celsius = $rounded;
    }

    public function plus(float $celsius): self
    {
        return new self($this->celsius + $celsius);
    }

    public function minus(float $celsius): self
    {
        return new self($this->celsius - $celsius);
    }

    public function equals(self $other): bool
    {
        return $this->celsius === $other->celsius;
    }

    public function isAbove(self $other): bool
    {
        return $this->celsius > $other->celsius;
    }

    public function isBelow(self $other): bool
    {
        return $this->celsius < $other->celsius;
    }

    public function isAtLeast(self $other): bool
    {
        return $this->celsius >= $other->celsius;
    }

    public function isAtMost(self $other): bool
    {
        return $this->celsius <= $other->celsius;
    }

    public function toFloat(): float
    {
        return $this->celsius;
    }
}
