<?php

declare(strict_types=1);

namespace App\Application\Engine\Opening;

use App\Application\Engine\State\StateStore;
use App\Domain\Decision\RoomConfiguration;

/**
 * Tells since when a room has a window or door open, from the cached states of its opening entities.
 */
final readonly class OpeningObserver
{
    private const array OPEN_STATES = ['on', 'open'];

    /**
     * The earliest instant, among the opening entities that are open now, at which they last changed; null when
     * all of them are closed. Unavailable and unknown entities count for nothing.
     */
    public function openSince(RoomConfiguration $room, StateStore $states): ?\DateTimeImmutable
    {
        $since = null;
        foreach ($room->openings as $opening) {
            $state = $states->get($opening);
            if (null === $state || !\in_array($state->state, self::OPEN_STATES, true)) {
                continue;
            }
            if (null === $since || $state->lastChanged < $since) {
                $since = $state->lastChanged;
            }
        }

        return $since;
    }
}
