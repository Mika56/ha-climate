<?php

declare(strict_types=1);

namespace App\Application\Engine\Facts;

use App\Application\Engine\EngineSnapshot;
use App\Application\Engine\State\StateStore;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\RoomFacts;
use App\Domain\HomeAssistant\EntityId;

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
        return $facts->withHouseEmptySince(self::emptySince($snapshot->configuration->trackedPersons, $snapshot->states));
    }

    /**
     * @param list<EntityId> $trackedPersons
     */
    public static function emptySince(array $trackedPersons, StateStore $states): ?\DateTimeImmutable
    {
        $since = null;
        foreach ($trackedPersons as $person) {
            $state = $states->get($person);
            if (null === $state || \in_array($state->state, ['unavailable', 'unknown'], true)) {
                continue;
            }

            if (self::HOME === $state->state) {
                return null;
            }

            if (null === $since || $state->lastChanged > $since) {
                $since = $state->lastChanged;
            }
        }

        return $since;
    }
}
