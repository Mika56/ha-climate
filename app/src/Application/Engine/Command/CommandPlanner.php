<?php

declare(strict_types=1);

namespace App\Application\Engine\Command;

use App\Application\Port\HomeAssistant\EntityState;
use App\Domain\Climate\HvacMode;
use App\Domain\Decision\Decision;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Device\Device;

/**
 * Compares what a decision wants from a device with the device's actual state, and plans the command
 * needed to bridge the gap, if any. A device that cannot operate in the decision's mode is wanted off.
 */
final readonly class CommandPlanner
{
    /** Tolerance when comparing target temperatures (devices report one decimal). */
    private const float TEMPERATURE_EPSILON = 0.05;

    public function plan(RoomConfiguration $room, Device $device, Decision $decision, EntityState $current): ?PlannedCommand
    {
        $target = $decision->target;
        if (HvacMode::Off === $decision->mode || null === $target || !$device->role->canOperateIn($decision->mode)) {
            return 'off' === $current->state ? null : PlannedCommand::turnOff($room->id, $device, $current);
        }

        $currentTarget = $current->attributes['temperature'] ?? null;
        if ($current->state === $decision->mode->value
            && is_numeric($currentTarget)
            && abs((float) $currentTarget - $target->toFloat()) < self::TEMPERATURE_EPSILON
        ) {
            return null;
        }

        return PlannedCommand::setTarget($room->id, $device, $decision->mode, $target, $current);
    }
}
