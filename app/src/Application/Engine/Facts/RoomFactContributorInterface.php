<?php

declare(strict_types=1);

namespace App\Application\Engine\Facts;

use App\Application\Engine\EngineSnapshot;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\RoomFacts;

/**
 * Extension point (service tag `app.room_fact_contributor`, applied automatically to implementations):
 * adds what it knows about a room to the facts handed to the rules. Contributors must be pure functions
 * of their input plus their own data sources, and return the facts unchanged when they know nothing.
 */
interface RoomFactContributorInterface
{
    public function contribute(RoomConfiguration $room, RoomFacts $facts, EngineSnapshot $snapshot): RoomFacts;
}
