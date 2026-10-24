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
 *
 * Hysteresis: a device already running in the wanted mode keeps its setpoint when the wanted one is closer
 * than the device's hysteresis (a command cannot be cancelled by a guard, so the skip lives here).
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
        $tolerance = max(self::TEMPERATURE_EPSILON, $device->antiCycling->hysteresis);
        if ($current->state === $decision->mode->value
            && is_numeric($currentTarget)
            && round(abs((float) $currentTarget - $target->toFloat()), 2) < $tolerance
        ) {
            return null;
        }

        return PlannedCommand::setTarget($room->id, $device, $decision->mode, $target, $current);
    }
}
