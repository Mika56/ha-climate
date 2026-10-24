<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

use App\Domain\Exception\InvalidValueException;

/**
 * Helpers for minute-of-day integers (0 = 00:00, 1439 = 23:59).
 */
final class MinuteOfDay
{
    public const int MAX = 1439;

    private function __construct()
    {
    }

    /**
     * @return int<0, 1439>
     */
    public static function assert(int $minute): int
    {
        if ($minute < 0 || $minute > self::MAX) {
            throw new InvalidValueException(\sprintf('Minute of day must be between 0 and %d, got %d.', self::MAX, $minute));
        }

        return $minute;
    }

    /**
     * @return int<0, 1439>
     */
    public static function clamp(int $minute): int
    {
        return max(0, min(self::MAX, $minute));
    }
}
