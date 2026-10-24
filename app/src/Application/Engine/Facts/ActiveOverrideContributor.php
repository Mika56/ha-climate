<?php

declare(strict_types=1);

namespace App\Application\Engine\Facts;

use App\Application\Engine\EngineSnapshot;
use App\Domain\Climate\Temperature;
use App\Domain\Climate\TemperatureDelta;
use App\Domain\Decision\ActiveOverride;
use App\Domain\Decision\OverrideType;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\RoomFacts;
use App\Domain\Exception\InvalidValueException;
use App\Infrastructure\Persistence\Entity\RoomOverride;
use App\Infrastructure\Persistence\Repository\RoomOverrideRepository;
use App\Infrastructure\Persistence\Repository\RoomRepository;
use Symfony\Component\Uid\Uuid;

/**
 * The boost or manual override of the room that is in force now (and matches the active season's mode).
 */
final readonly class ActiveOverrideContributor implements RoomFactContributorInterface
{
    public function __construct(
        private RoomRepository $rooms,
        private RoomOverrideRepository $overrides,
    ) {
    }

    public function contribute(RoomConfiguration $room, RoomFacts $facts, EngineSnapshot $snapshot): RoomFacts
    {
        $entity = $this->rooms->find(Uuid::fromString((string) $room->id));
        $override = null === $entity ? null : $this->overrides->findActiveForRoom($entity, $snapshot->now);

        if (null === $override || !$override->isActiveAt($snapshot->now) || $override->getMode() !== $snapshot->configuration->season->activeHvacMode()) {
            return $facts->withOverride(null);
        }

        try {
            return $facts->withOverride($this->toDomain($override));
        } catch (InvalidValueException) {
            return $facts->withOverride(null);
        }
    }

    private function toDomain(RoomOverride $override): ActiveOverride
    {
        $delta = $override->getDelta();
        $target = $override->getAbsoluteTarget();

        if (OverrideType::Boost === $override->getType() && null !== $delta) {
            return ActiveOverride::boost(new TemperatureDelta($delta), $override->getEndsAt(), $override->isUntilNextSlot());
        }
        if (null === $target) {
            throw new InvalidValueException('A manual override requires a target.');
        }

        return ActiveOverride::manual(new Temperature($target), $override->getEndsAt(), $override->isUntilNextSlot());
    }
}
