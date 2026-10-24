<?php

declare(strict_types=1);

namespace App\Application\Engine\Command;

use App\Application\Engine\EngineSnapshot;
use App\Domain\Decision\Reason;

/**
 * Protects the hardware from short cycles with the device's {@see \App\Domain\Device\AntiCyclingPolicy}.
 *
 * - Turning a device on that has been off for less than `minOffSeconds` is postponed until it has been off
 *   long enough.
 * - Turning a device off that has been on for less than `minOnSeconds` is postponed until it has run long
 *   enough.
 * - Changing the setpoint (or the mode between heat and cool) of a device that stays on is never delayed.
 *
 * "Off since" / "on since" is the `last_changed` of the device's Home Assistant state: it covers changes made
 * by hand and survives an engine restart, with no bookkeeping of our own. The delay is exact: the command is
 * allowed at the very instant the minimum duration has elapsed.
 *
 * The guard is uniform on purpose: it also postpones a switch-off wanted by the season, an open window or
 * a manual "off", because its job is to keep the compressor or the boiler safe. The cost is bounded by the
 * (user-chosen) minimum on duration.
 *
 * The hysteresis of the policy is applied earlier, when the command is planned (see CommandPlanner): a guard
 * can only allow or postpone a command, not cancel it.
 */
final readonly class AntiCyclingGuard implements CommandGuardInterface
{
    public const string REASON_MIN_OFF = 'reason.anti_cycling_min_off';
    public const string REASON_MIN_ON = 'reason.anti_cycling_min_on';

    public function check(PlannedCommand $command, EngineSnapshot $snapshot): GuardVerdict
    {
        $policy = $command->device->antiCycling;

        if ($command->isTurningOn()) {
            $minimum = $policy->minOffSeconds;
            $code = self::REASON_MIN_OFF;
        } elseif ($command->isTurningOff()) {
            $minimum = $policy->minOnSeconds;
            $code = self::REASON_MIN_ON;
        } else {
            return GuardVerdict::allow();
        }

        $until = $command->current->lastChanged->modify(\sprintf('+%d seconds', $minimum));
        if ($minimum <= 0 || $snapshot->now >= $until) {
            return GuardVerdict::allow();
        }

        return GuardVerdict::deferUntil($until, new Reason($code, [
            'device' => (string) $command->device->entityId,
            'seconds' => $minimum,
            'until' => $until->format(\DATE_ATOM),
        ]));
    }
}
