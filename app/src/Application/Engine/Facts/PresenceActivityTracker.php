<?php

declare(strict_types=1);

namespace App\Application\Engine\Facts;

use App\Application\Engine\Configuration\EngineConfiguration;
use App\Domain\Room\PresenceSignal;
use App\Domain\Room\RoomId;

/**
 * Remembers, for each presence signal of each room, since when it has been inactive.
 *
 * The last change of an entity is not enough: a power sensor in standby changes every minute, a room sensor
 * moves from room to room, a player goes from idle to off, and none of that means somebody came back. So the
 * instant that counts is the one at which the signal was first seen inactive after having been active.
 *
 * - an active signal has no entry;
 * - an inactive signal without entry gets the last change of its entity (on the first observation, for
 *   example after a restart, that is the safe side: the vacancy starts as late as it plausibly can);
 * - an inactive signal with an entry keeps it.
 *
 * Entries are in memory only and keyed by room, entity and rule, so editing a signal starts afresh.
 */
final class PresenceActivityTracker
{
    /** @var array<string, \DateTimeImmutable> */
    private array $inactiveSince = [];

    /**
     * Records what a usable state says about the signal and returns since when it is inactive, or null while
     * it is active.
     */
    public function observe(RoomId $room, PresenceSignal $signal, string $state, \DateTimeImmutable $lastChanged): ?\DateTimeImmutable
    {
        $key = self::key($room, $signal);
        if ($signal->isActive($state)) {
            unset($this->inactiveSince[$key]);

            return null;
        }

        return $this->inactiveSince[$key] ??= $lastChanged;
    }

    /**
     * Forgets the signals that are no longer configured.
     */
    public function retain(EngineConfiguration $configuration): void
    {
        $keys = [];
        foreach ($configuration->rooms as $room) {
            foreach ($room->presenceSignals as $signal) {
                $keys[self::key($room->id, $signal)] = true;
            }
        }
        $this->inactiveSince = array_intersect_key($this->inactiveSince, $keys);
    }

    private static function key(RoomId $room, PresenceSignal $signal): string
    {
        return implode("\0", [(string) $room, (string) $signal->entityId, $signal->kind->value, (string) $signal->threshold, (string) $signal->value]);
    }
}
