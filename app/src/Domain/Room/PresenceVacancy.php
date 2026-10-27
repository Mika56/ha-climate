<?php

declare(strict_types=1);

namespace App\Domain\Room;

/**
 * Since when a room is vacant, from its presence signals.
 *
 * - a signal whose entity is missing, `unavailable` or `unknown` is ignored;
 * - without any usable signal (none configured, or all ignored) the room is not known to be vacant;
 * - one active usable signal means the room is occupied;
 * - otherwise the room is vacant since the most recent `since` of the usable signals, that is the instant at
 *   which the last of them became inactive. The caller supplies it: the last change of the entity is not
 *   it (a power sensor in standby changes all the time), see the activity tracker of the engine.
 */
final class PresenceVacancy
{
    /** States that say nothing about the room. */
    public const array UNUSABLE_STATES = ['unavailable', 'unknown'];

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
            if (null === $reading || \in_array($reading->state, self::UNUSABLE_STATES, true)) {
                continue;
            }
            if ($signal->isActive($reading->state)) {
                return null;
            }
            if (null === $since || $reading->since > $since) {
                $since = $reading->since;
            }
        }

        return $since;
    }
}
