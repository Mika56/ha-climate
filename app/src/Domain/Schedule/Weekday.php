<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

/**
 * ISO-8601 day of week (Monday = 1 ... Sunday = 7).
 */
enum Weekday: int
{
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;
    case Sunday = 7;

    public static function fromDate(\DateTimeImmutable $date): self
    {
        return self::from((int) $date->format('N'));
    }

    public function previous(): self
    {
        return self::from(($this->value + 5) % 7 + 1);
    }

    public function next(): self
    {
        return self::from($this->value % 7 + 1);
    }
}
