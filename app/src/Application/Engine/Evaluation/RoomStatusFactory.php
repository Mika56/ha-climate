<?php

declare(strict_types=1);

namespace App\Application\Engine\Evaluation;

use App\Application\Engine\EngineSnapshot;
use App\Domain\Decision\Decision;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\RoomFacts;

final readonly class RoomStatusFactory
{
    public function create(RoomConfiguration $room, Decision $decision, RoomFacts $facts, EngineSnapshot $snapshot): RoomStatus
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
            );
        }

        return new RoomStatus($room->id, $room->name, $facts->currentTemperature, $decision, $facts->override, $devices, $snapshot->now);
    }
}
