<?php

declare(strict_types=1);

namespace App\Application\Engine\State;

use App\Application\Engine\Configuration\EngineConfiguration;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\HomeAssistant\EntityId;
use App\Domain\Room\RoomId;
use App\Domain\Schedule\AnchoredSlotStart;

/**
 * Routes an entity id to the rooms its changes can affect, and lists the entities worth subscribing to.
 *
 * A room is affected by its temperature sensor, presence signals, openings, devices and the anchors of the
 * schedules it follows; global entities (tracked persons, weather) affect every room.
 */
final class EntityRoomIndex
{
    /** @var array<string, array<string, RoomId>> entity id => room id => room id */
    private array $rooms = [];

    public function rebuild(EngineConfiguration $configuration): void
    {
        $this->rooms = [];

        $globals = $configuration->trackedPersons;
        if (null !== $configuration->weatherEntity) {
            $globals[] = $configuration->weatherEntity;
        }

        foreach ($configuration->rooms as $room) {
            foreach ($this->roomEntities($room, $configuration) as $entity) {
                $this->add($entity, $room->id);
            }
            foreach ($globals as $global) {
                $this->add($global, $room->id);
            }
        }
        ksort($this->rooms);
    }

    /**
     * @return list<RoomId>
     */
    public function roomsFor(string $entityId): array
    {
        return array_values($this->rooms[$entityId] ?? []);
    }

    public function tracks(string $entityId): bool
    {
        return isset($this->rooms[$entityId]);
    }

    /**
     * Every entity to subscribe to: never "everything", and nothing when no room is configured.
     *
     * @return list<string>
     */
    public function entityIds(): array
    {
        return array_keys($this->rooms);
    }

    /**
     * @return list<EntityId>
     */
    private function roomEntities(RoomConfiguration $room, EngineConfiguration $configuration): array
    {
        $entities = [...$room->openings];
        if (null !== $room->temperatureSensor) {
            $entities[] = $room->temperatureSensor;
        }
        foreach ($room->presenceSignals as $signal) {
            $entities[] = $signal->entityId;
        }
        foreach ($room->devices as $device) {
            $entities[] = $device->entityId;
        }
        foreach ([$room->heatingSchedule, $room->coolingSchedule] as $scheduleId) {
            foreach ($configuration->schedule($scheduleId)->slots ?? [] as $slot) {
                if ($slot->start instanceof AnchoredSlotStart) {
                    $entities[] = $slot->start->anchor;
                }
            }
        }

        return $entities;
    }

    private function add(EntityId $entity, RoomId $room): void
    {
        $this->rooms[(string) $entity][(string) $room] = $room;
    }
}
