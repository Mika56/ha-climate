<?php

declare(strict_types=1);

namespace App\Application\Engine\Facts;

use App\Application\Engine\EngineSnapshot;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\RoomFacts;

/**
 * Since when nobody is home: the latest change among the tracked persons, when none of them is `home`.
 * Persons whose state is unavailable or unknown say nothing and are ignored; without any tracked person
 * (or any usable one) the feature is off and the house is never considered empty.
 */
final readonly class HouseEmptyContributor implements RoomFactContributorInterface
{
    private const string HOME = 'home';

    public function contribute(RoomConfiguration $room, RoomFacts $facts, EngineSnapshot $snapshot): RoomFacts
    {
        $since = null;
        foreach ($snapshot->configuration->trackedPersons as $person) {
            $state = $snapshot->states->get($person);
            if (null === $state || \in_array($state->state, ['unavailable', 'unknown'], true)) {
                continue;
            }

            if (self::HOME === $state->state) {
                return $facts->withHouseEmptySince(null);
            }

            if (null === $since || $state->lastChanged > $since) {
                $since = $state->lastChanged;
            }
        }

        return $facts->withHouseEmptySince($since);
    }
}
