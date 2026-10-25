<?php

declare(strict_types=1);

namespace App\Application\Engine\Evaluation;

use App\Application\Engine\Alert\AlertView;
use App\Application\Engine\EngineSnapshot;
use App\Domain\Decision\Decision;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\RoomFacts;

final readonly class RoomStatusFactory
{
    /**
     * @param list<AlertView> $alerts the active alerts of the room
     */
    public function create(RoomConfiguration $room, Decision $decision, RoomFacts $facts, EngineSnapshot $snapshot, array $alerts = []): RoomStatus
    {
        $devices = [];
        foreach ($room->devices as $device) {
            $state = $snapshot->states->get($device->entityId);
            $target = $state?->attributes['temperature'] ?? null;
            $current = $state?->attributes['current_temperature'] ?? null;

            $devices[] = new DeviceStatus(
                (string) $device->entityId,
                $device->role->value,
                $state?->state,
                is_numeric($target) ? (float) $target : null,
                is_numeric($current) ? (float) $current : null,
                self::running($state?->attributes['hvac_action'] ?? null),
            );
        }

        return new RoomStatus($room->id, $room->name, $facts->currentTemperature, $decision, $facts->override, $devices, $snapshot->now, $facts, $alerts);
    }

    /**
     * `hvac_action` tells what the device is doing right now: heating and cooling mean running, any other
     * action (idle, off, drying, fan...) means it is not; a device that does not report one gives null.
     */
    private static function running(mixed $action): ?bool
    {
        return \is_string($action) ? \in_array($action, ['heating', 'cooling'], true) : null;
    }
}
