<?php

declare(strict_types=1);

namespace App\Application\Engine\Facts;

use App\Application\Engine\EngineSnapshot;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\RoomFacts;
use App\Domain\Room\PresenceReading;
use App\Domain\Room\PresenceVacancy;

/**
 * Since when nobody is in the room, from its presence signals (see {@see PresenceVacancy}). A room without
 * signal keeps the facts untouched; a room whose signals are all missing, unavailable or unknown is not known
 * to be vacant.
 */
final readonly class RoomVacancyContributor implements RoomFactContributorInterface
{
    public function contribute(RoomConfiguration $room, RoomFacts $facts, EngineSnapshot $snapshot): RoomFacts
    {
        if ([] === $room->presenceSignals) {
            return $facts;
        }

        $readings = [];
        foreach ($room->presenceSignals as $signal) {
            $state = $snapshot->states->get($signal->entityId);
            if (null !== $state) {
                $readings[(string) $signal->entityId] = new PresenceReading($state->state, $state->lastChanged);
            }
        }

        return $facts->withRoomVacantSince(PresenceVacancy::since($room->presenceSignals, $readings));
    }
}
