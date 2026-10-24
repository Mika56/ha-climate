<?php

declare(strict_types=1);

namespace App\Application\Engine\Facts;

use App\Application\Engine\EngineSnapshot;
use App\Application\Engine\Opening\OpeningObserver;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\RoomFacts;

/**
 * Since when a window or door of the room is open (null when all are closed).
 */
final readonly class OpeningContributor implements RoomFactContributorInterface
{
    public function __construct(private OpeningObserver $observer)
    {
    }

    public function contribute(RoomConfiguration $room, RoomFacts $facts, EngineSnapshot $snapshot): RoomFacts
    {
        return $facts->withOpeningOpenSince($this->observer->openSince($room, $snapshot->states));
    }
}
