<?php

declare(strict_types=1);

namespace App\Application\Engine\Alert;

use App\Application\Port\HomeAssistant\EntityState;
use App\Domain\Climate\HvacMode;

/**
 * Whether a device state is the one a command asked for: same mode and, for a command that sets a target,
 * the same target within the precision devices report (devices round to halves or tenths).
 */
final readonly class CommandedStateMatcher
{
    private const float TEMPERATURE_EPSILON = 0.05;

    public function matches(EntityState $state, HvacMode $mode, ?float $temperature): bool
    {
        if ($state->state !== $mode->value) {
            return false;
        }

        $reported = $state->attributes['temperature'] ?? null;
        if (HvacMode::Off === $mode || null === $temperature || !is_numeric($reported)) {
            return true;
        }

        return abs((float) $reported - $temperature) < self::TEMPERATURE_EPSILON;
    }
}
