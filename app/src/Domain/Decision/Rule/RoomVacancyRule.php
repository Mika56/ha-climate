<?php

declare(strict_types=1);

namespace App\Domain\Decision\Rule;

use App\Domain\Climate\HvacMode;
use App\Domain\Decision\Decision;
use App\Domain\Decision\Reason;
use App\Domain\Decision\RoomEvaluationContext;
use App\Domain\Decision\RoomRuleInterface;

/**
 * Lowers the comfort of a room nobody is in: after the room's vacancy delay, the vacancy preset replaces the
 * target, but only when it is more economical than what the rest of the chain decided (lower when heating,
 * higher when cooling). Before the delay elapses, the room asks to be evaluated again at that instant.
 */
final readonly class RoomVacancyRule implements RoomRuleInterface
{
    public static function priority(): int
    {
        return 400;
    }

    public function apply(RoomEvaluationContext $context, callable $next): Decision
    {
        $since = $context->facts->roomVacantSince;
        $decision = $next($context);
        if (null === $since) {
            return $decision;
        }

        $deadline = $since->modify(\sprintf('+%d seconds', $context->room->vacancyDelay));
        if ($deadline > $context->now) {
            return $decision->withReevaluateAt($deadline);
        }

        $mode = $context->mode();
        if (HvacMode::Off === $mode || null === $decision->target) {
            return $decision;
        }

        $preset = $context->policy->vacancyPreset;
        $economical = $context->room->presetTemperatures->for($preset, $mode);
        $saves = HvacMode::Heat === $mode ? $economical->isBelow($decision->target) : $economical->isAbove($decision->target);
        if (!$saves) {
            return $decision;
        }

        return new Decision(
            $mode,
            $economical,
            new Reason('reason.room_vacant', ['preset' => (string) $preset, 'since' => $since->format(\DATE_ATOM)]),
            $decision->reevaluateAt,
        );
    }
}
