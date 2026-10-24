<?php

declare(strict_types=1);

namespace App\Domain\Decision\Rule;

use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Temperature;
use App\Domain\Decision\ActiveOverride;
use App\Domain\Decision\Decision;
use App\Domain\Decision\OverrideType;
use App\Domain\Decision\Reason;
use App\Domain\Decision\RoomEvaluationContext;
use App\Domain\Decision\RoomRuleInterface;

/**
 * Applies the boost or manual override in force.
 *
 * - Boost: the remaining rules give the base decision, the boost moves its target by the delta (up when
 *   heating, down when cooling, clamped to the temperature range). An "off" base stays off: a boost never
 *   switches a room on against the rules below it.
 * - Manual: the absolute target (or "off") chosen by the user wins over everything below, schedule included.
 *
 * Priority 500: the rules above it (season off 1000, no device 900, vacation 800, house empty 700, opening
 * 600) answer first and the override is then not even consulted: an open window or an empty house beats a
 * boost or a manual setting. The rules below it (room vacancy 400, schedule) are the "base" of a boost and
 * are ignored by a manual override.
 *
 * The override ends at its `endsAt` (always concrete, see {@see ActiveOverride}): the decision asks to be
 * evaluated again then, so the room falls back to the schedule with no event from Home Assistant.
 */
final readonly class OverrideRule implements RoomRuleInterface
{
    public static function priority(): int
    {
        return 500;
    }

    public function apply(RoomEvaluationContext $context, callable $next): Decision
    {
        $override = $context->facts->override;
        $mode = $context->mode();

        if (null === $override || HvacMode::Off === $mode || $override->hasEndedAt($context->now)) {
            return $next($context);
        }

        $decision = OverrideType::Boost === $override->type
            ? $this->boosted($context, $override, $next)
            : $this->manual($context, $override, $mode);

        return null === $override->endsAt ? $decision : $decision->withReevaluateAt($override->endsAt);
    }

    /**
     * @param callable(RoomEvaluationContext): Decision $next
     */
    private function boosted(RoomEvaluationContext $context, ActiveOverride $override, callable $next): Decision
    {
        $base = $next($context);
        $delta = $override->delta;
        if ($base->isOff() || null === $base->target || null === $delta) {
            return $base;
        }

        $reason = new Reason('reason.boost', ['delta' => $delta->toFloat(), 'until' => self::until($context, $override)]);
        $shift = HvacMode::Heat === $base->mode ? $delta->toFloat() : -$delta->toFloat();
        $target = new Temperature(max(Temperature::MIN, min(Temperature::MAX, $base->target->toFloat() + $shift)));

        return new Decision($base->mode, $target, $reason, $base->reevaluateAt);
    }

    private function manual(RoomEvaluationContext $context, ActiveOverride $override, HvacMode $mode): Decision
    {
        $reason = new Reason('reason.manual_override', ['until' => self::until($context, $override)]);
        if (null === $override->target) {
            return Decision::off($reason);
        }

        return HvacMode::Heat === $mode ? Decision::heat($override->target, $reason) : Decision::cool($override->target, $reason);
    }

    private static function until(RoomEvaluationContext $context, ActiveOverride $override): ?string
    {
        return $override->endsAt?->setTimezone($context->now->getTimezone())->format('H:i');
    }
}
