<?php

declare(strict_types=1);

namespace App\Domain\Decision\Rule;

use App\Domain\Decision\Decision;
use App\Domain\Decision\Reason;
use App\Domain\Decision\RoomEvaluationContext;
use App\Domain\Decision\RoomRuleInterface;

/**
 * Switches the room off when none of its devices can operate in the season's mode.
 */
final readonly class NoDeviceForModeRule implements RoomRuleInterface
{
    public static function priority(): int
    {
        return 900;
    }

    public function apply(RoomEvaluationContext $context, callable $next): Decision
    {
        $mode = $context->mode();

        if (!$context->room->hasDeviceFor($mode)) {
            return Decision::off(new Reason('reason.no_device', ['mode' => $mode->value]));
        }

        return $next($context);
    }
}
