<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

use App\Domain\Exception\InvalidValueException;

/**
 * Opaque weekly schedule identifier.
 */
final readonly class ScheduleId implements \Stringable
{
    private string $value;

    public function __construct(string $value)
    {
        if ('' === trim($value)) {
            throw new InvalidValueException('Schedule id must not be empty.');
        }

        $this->value = $value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
