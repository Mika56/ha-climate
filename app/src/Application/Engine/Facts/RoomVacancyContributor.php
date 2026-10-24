<?php

declare(strict_types=1);

namespace App\Application\Engine\Facts;

use App\Application\Engine\EngineSnapshot;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\RoomFacts;

/**
 * Since when nobody is in the room: the last change of its presence entity when it reads `off`. A present
 * (`on`), missing, unavailable or unknown presence entity means the room is not known to be vacant.
 */
final readonly class RoomVacancyContributor implements RoomFactContributorInterface
{
    public function contribute(RoomConfiguration $room, RoomFacts $facts, EngineSnapshot $snapshot): RoomFacts
    {
        if (null === $room->presence) {
            return $facts;
        }

        $state = $snapshot->states->get($room->presence);

        return $facts->withRoomVacantSince('off' === $state?->state ? $state->lastChanged : null);
    }
}
