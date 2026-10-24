<?php

declare(strict_types=1);

namespace App\Application\Engine\Facts;

use App\Application\Engine\EngineSnapshot;
use App\Application\Engine\State\StateStore;
use App\Domain\Climate\Temperature;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\RoomFacts;
use App\Domain\Exception\InvalidValueException;

/**
 * Current temperature of the room: the numeric state of its temperature sensor. An unavailable, unknown or
 * non-numeric state (or an absurd reading) means "no temperature".
 */
final readonly class CurrentTemperatureContributor implements RoomFactContributorInterface
{
    public function contribute(RoomConfiguration $room, RoomFacts $facts, EngineSnapshot $snapshot): RoomFacts
    {
        if (null === $room->temperatureSensor) {
            return $facts;
        }

        return $facts->withCurrentTemperature(self::read($room, $snapshot->states));
    }

    /**
     * The room's temperature as the sensor currently reports it, if it does so in a usable way.
     */
    public static function read(RoomConfiguration $room, StateStore $states): ?Temperature
    {
        if (null === $room->temperatureSensor) {
            return null;
        }

        $state = $states->get($room->temperatureSensor)?->state;
        if (null === $state || !is_numeric($state)) {
            return null;
        }

        try {
            return new Temperature((float) $state);
        } catch (InvalidValueException) {
            return null;
        }
    }
}
