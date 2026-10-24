<?php

declare(strict_types=1);

namespace App\Domain\Decision;

use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Temperature;

/**
 * How long before a slot a room must start heating (or cooling) to be at the slot's target when it begins.
 *
 * The distance to cover is measured from the lower of the current temperature and the reference target (the
 * target in force before the slot) when heating, from the higher one when cooling. Using the reference as a
 * bound keeps the lead stable while the room warms up past it, so the decision does not flip back and forth
 * once the preheat has started.
 */
final class PreheatLead
{
    /** Pessimistic speed assumed until the room's own rate has been learned, in °C per hour. */
    public const float DEFAULT_RATE_PER_HOUR = 1.0;

    private function __construct()
    {
    }

    /**
     * Whether $candidate asks more of the equipment than $reference: higher when heating, lower when cooling.
     */
    public static function isHarder(HvacMode $mode, Temperature $reference, Temperature $candidate): bool
    {
        return HvacMode::Heat === $mode ? $candidate->isAbove($reference) : $candidate->isBelow($reference);
    }

    /**
     * @param float|null $ratePerHour learned speed in °C per hour; the default rate is used when unknown or not positive
     */
    public static function seconds(
        HvacMode $mode,
        Temperature $target,
        Temperature $reference,
        ?Temperature $current,
        ?float $ratePerHour,
        int $maxMinutes,
    ): int {
        $from = $current ?? $reference;
        $distance = HvacMode::Heat === $mode
            ? $target->toFloat() - min($from->toFloat(), $reference->toFloat())
            : max($from->toFloat(), $reference->toFloat()) - $target->toFloat();

        if ($distance <= 0.0) {
            return 0;
        }

        $rate = null !== $ratePerHour && $ratePerHour > 0.0 ? $ratePerHour : self::DEFAULT_RATE_PER_HOUR;

        return min((int) ceil($distance / $rate * 3600), $maxMinutes * 60);
    }
}
