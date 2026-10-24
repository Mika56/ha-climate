<?php

declare(strict_types=1);

namespace App\Application\Engine\State;

use App\Domain\HomeAssistant\EntityId;
use App\Domain\Schedule\TimeAnchorResolverInterface;

/**
 * Reads the time of an `input_datetime` entity from the state store.
 *
 * Home Assistant exposes `hour` and `minute` attributes when the helper has a time part; a time-only helper
 * also carries the time as its state ("22:30:00").
 */
final readonly class TimeAnchorResolver implements TimeAnchorResolverInterface
{
    public function __construct(private StateStore $states)
    {
    }

    public function minuteOfDay(EntityId $anchor): ?int
    {
        $state = $this->states->get($anchor);
        if (null === $state) {
            return null;
        }

        $hour = $state->attributes['hour'] ?? null;
        $minute = $state->attributes['minute'] ?? null;
        if (true === ($state->attributes['has_time'] ?? null) && \is_int($hour) && \is_int($minute)) {
            return self::validMinute($hour, $minute);
        }

        if (1 === preg_match('/^(\d{2}):(\d{2})(?::\d{2}(?:\.\d+)?)?$/D', $state->state, $matches)) {
            return self::validMinute((int) $matches[1], (int) $matches[2]);
        }

        return null;
    }

    private static function validMinute(int $hour, int $minute): ?int
    {
        return $hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59 ? $hour * 60 + $minute : null;
    }
}
