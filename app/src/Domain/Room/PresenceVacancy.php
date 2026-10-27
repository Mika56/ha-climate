<?php

declare(strict_types=1);

namespace App\Domain\Room;

/**
 * Since when a room is vacant, from its presence signals.
 *
 * - a signal whose entity is missing, `unavailable` or `unknown` is ignored;
 * - without any usable signal (none configured, or all ignored) the room is not known to be vacant;
 * - one active usable signal means the room is occupied;
 * - otherwise the room is vacant since the most recent change of the usable signals.
 *
 * For `above` and `equals` signals the last change is the last change of the state, not of the "active" status
 * (a power sensor changes all the time): the vacancy then starts a bit late, which is the safe side for a
 * heating that waits for the room to be empty.
 */
final class PresenceVacancy
{
    private function __construct()
    {
    }

    /**
     * @param list<PresenceSignal>           $signals
     * @param array<string, PresenceReading> $readings by entity id
     */
    public static function since(array $signals, array $readings): ?\DateTimeImmutable
    {
        $since = null;
        foreach ($signals as $signal) {
            $reading = $readings[(string) $signal->entityId] ?? null;
            if (null === $reading || \in_array($reading->state, ['unavailable', 'unknown'], true)) {
                continue;
            }
            if ($signal->isActive($reading->state)) {
                return null;
            }
            if (null === $since || $reading->lastChanged > $since) {
                $since = $reading->lastChanged;
            }
        }

        return $since;
    }
}
