<?php

declare(strict_types=1);

namespace App\Domain\Decision\Rule;

use App\Domain\Decision\Decision;
use App\Domain\Decision\PreheatLead;
use App\Domain\Decision\Reason;
use App\Domain\Decision\RoomEvaluationContext;
use App\Domain\Decision\RoomRuleInterface;

/**
 * Anticipates a more demanding slot (warmer when heating, cooler when cooling) so the room is at the slot's
 * target when the slot starts. Kept apart from the schedule rule, which stays a pure "slot in force" lookup:
 * this rule wraps its answer and only ever replaces the target by the next slot's one, earlier.
 *
 * It sits just above the schedule rule so every rule above it (vacancy, override...) sees the anticipated target.
 */
final readonly class PreheatRule implements RoomRuleInterface
{
    public static function priority(): int
    {
        return -900;
    }

    public function apply(RoomEvaluationContext $context, callable $next): Decision
    {
        $decision = $next($context);
        $mode = $context->mode();
        $schedule = $context->schedule;
        if (!$context->room->preheatEnabled || null === $decision->target || null === $schedule || $schedule->mode !== $mode) {
            return $decision;
        }

        $following = $schedule->nextSlotAfter($context->now, $context->anchors);
        $nextTarget = $context->room->presetTemperatures->for($following->presetKey(), $mode);
        if (!PreheatLead::isHarder($mode, $decision->target, $nextTarget)) {
            return $decision;
        }

        $lead = PreheatLead::seconds(
            $mode,
            $nextTarget,
            $decision->target,
            $context->facts->currentTemperature,
            $context->facts->heatingRatePerHour,
            $context->room->preheatMaxMinutes,
        );
        $startAt = $following->start->modify(\sprintf('-%d seconds', $lead));
        if ($startAt > $context->now) {
            return $decision->withReevaluateAt($startAt);
        }

        return new Decision(
            $mode,
            $nextTarget,
            new Reason('reason.preheat', ['preset' => (string) $following->presetKey(), 'at' => $following->start->format(\DATE_ATOM)]),
            $decision->reevaluateAt,
        );
    }
}
