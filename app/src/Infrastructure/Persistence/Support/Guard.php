<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Support;

use App\Domain\Climate\HvacMode;

/**
 * Tiny invariant helpers shared by the persistence entities.
 *
 * @internal
 */
final class Guard
{
    private function __construct()
    {
    }

    public static function notBlank(string $value, string $field): string
    {
        if ('' === trim($value)) {
            throw new \InvalidArgumentException(sprintf('"%s" must not be blank.', $field));
        }

        return $value;
    }

    public static function nullableNotBlank(?string $value, string $field): ?string
    {
        return null === $value ? null : self::notBlank($value, $field);
    }

    public static function nonNegativeInt(int $value, string $field): int
    {
        if ($value < 0) {
            throw new \InvalidArgumentException(sprintf('"%s" must not be negative.', $field));
        }

        return $value;
    }

    public static function nonNegativeFloat(float $value, string $field): float
    {
        if ($value < 0.0) {
            throw new \InvalidArgumentException(sprintf('"%s" must not be negative.', $field));
        }

        return $value;
    }

    public static function positiveInt(int $value, string $field): int
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException(sprintf('"%s" must be strictly positive.', $field));
        }

        return $value;
    }

    public static function between(int $value, int $min, int $max, string $field): int
    {
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException(sprintf('"%s" must be between %d and %d.', $field, $min, $max));
        }

        return $value;
    }

    public static function slug(string $value, string $field): string
    {
        if (1 !== preg_match('/^[a-z0-9][a-z0-9_-]*$/', $value)) {
            throw new \InvalidArgumentException(sprintf('"%s" must be a lowercase slug.', $field));
        }

        return $value;
    }

    public static function hexColor(string $value, string $field): string
    {
        if (1 !== preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            throw new \InvalidArgumentException(sprintf('"%s" must be a #RRGGBB color.', $field));
        }

        return $value;
    }

    /**
     * Only heat or cool can be configured; "off" is a runtime outcome, not a configurable mode.
     */
    public static function activeMode(HvacMode $mode, string $field): HvacMode
    {
        if (HvacMode::Off === $mode) {
            throw new \InvalidArgumentException(sprintf('"%s" must be heat or cool.', $field));
        }

        return $mode;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    public static function stringList(array $values, string $field): array
    {
        return array_values(array_unique(array_map(
            static fn (string $v): string => self::notBlank($v, $field),
            $values,
        )));
    }
}
