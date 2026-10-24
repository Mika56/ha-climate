<?php

declare(strict_types=1);

namespace App\Application\Engine\Facts;

use App\Application\Engine\EngineSnapshot;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\RoomFacts;

/**
 * Starts from "no fact known" and lets every tagged contributor fill in what it knows.
 */
final readonly class RoomFactsBuilder
{
    /**
     * @param iterable<RoomFactContributorInterface> $contributors
     */
    public function __construct(private iterable $contributors)
    {
    }

    public function build(RoomConfiguration $room, EngineSnapshot $snapshot): RoomFacts
    {
        $facts = RoomFacts::none();
        foreach ($this->contributors as $contributor) {
            $facts = $contributor->contribute($room, $facts, $snapshot);
        }

        return $facts;
    }
}
