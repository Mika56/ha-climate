<?php

declare(strict_types=1);

namespace App\Application\Engine\Alert;

use App\Application\Engine\Configuration\EngineConfiguration;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Schedule\AnchoredSlotStart;

/**
 * The entities whose unavailability matters, with the rooms that rely on them: temperature sensors, presence
 * entities, openings, devices and the anchors of the schedules the rooms follow. The global entities (tracked
 * persons, weather) are not part of it.
 */
final readonly class MonitoredEntities
{
    /**
     * @return array<string, list<RoomConfiguration>> rooms per entity id
     */
    public function all(EngineConfiguration $configuration): array
    {
        $monitored = [];
        foreach ($configuration->rooms as $room) {
            foreach ($this->entitiesOf($room, $configuration) as $entityId) {
                $monitored[$entityId][$this->roomKey($room)] = $room;
            }
        }

        return array_map(array_values(...), $monitored);
    }

    /**
     * @return list<RoomConfiguration>
     */
    public function roomsUsing(EngineConfiguration $configuration, string $entityId): array
    {
        return $this->all($configuration)[$entityId] ?? [];
    }

    /**
     * @return list<string>
     */
    private function entitiesOf(RoomConfiguration $room, EngineConfiguration $configuration): array
    {
        $entities = [...$room->openings];
        foreach ([$room->temperatureSensor, $room->presence] as $optional) {
            if (null !== $optional) {
                $entities[] = $optional;
            }
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

        return array_map(strval(...), $entities);
    }

    private function roomKey(RoomConfiguration $room): string
    {
        return (string) $room->id;
    }
}
