<?php

declare(strict_types=1);

namespace App\Domain\Decision\Rule;

use App\Domain\Climate\HvacMode;
use App\Domain\Decision\Decision;
use App\Domain\Decision\PreheatLead;
use App\Domain\Decision\Reason;
use App\Domain\Decision\RoomEvaluationContext;
use App\Domain\Decision\RoomRuleInterface;
use App\Domain\Decision\VacationPeriod;
use App\Domain\Preset\PresetKey;

/**
 * Vacation: from its start until the room's preheat lead before the return, the room is off or kept at the
 * absent preset. In the lead window the schedule runs again, with the target of the slot in force at the
 * return as a floor, so the room is at its target when people come back. The lead is zero for a room
 * without preheat. Each phase change is announced as a deadline.
 */
final readonly class VacationRule implements RoomRuleInterface
{
    public static function priority(): int
    {
        return 800;
    }

    public function apply(RoomEvaluationContext $context, callable $next): Decision
    {
        $vacation = $context->facts->vacation;
        $mode = $context->mode();
        if (null === $vacation) {
            return $next($context);
        }

        if ($context->now < $vacation->start) {
            return $next($context)->withReevaluateAt($vacation->start);
        }

        if (!$vacation->isActiveAt($context->now)) {
            return $next($context);
        }

        $presetAtReturn = $this->presetAtReturn($context, $vacation, $mode);
        $resumeAt = $vacation->returnAt->modify(\sprintf('-%d seconds', $this->leadSeconds($context, $mode, $presetAtReturn)));
        if ($context->now < $resumeAt) {
            return $this->away($context, $vacation, $mode)->withReevaluateAt($resumeAt);
        }

        return $this->raisedToReturnTarget($context, $vacation, $mode, $presetAtReturn, $next($context)->withReevaluateAt($vacation->returnAt));
    }

    private function away(RoomEvaluationContext $context, VacationPeriod $vacation, HvacMode $mode): Decision
    {
        $reason = new Reason('reason.vacation', ['returnAt' => $vacation->returnAt->format(\DATE_ATOM)]);
        if ($vacation->offDuringVacation) {
            return Decision::off($reason);
        }

        $target = $context->room->presetTemperatures->for($context->policy->absentPreset, $mode);

        return HvacMode::Heat === $mode ? Decision::heat($target, $reason) : Decision::cool($target, $reason);
    }

    /**
     * The preset of the slot in force when people come back, if the room follows a schedule in this mode.
     */
    private function presetAtReturn(RoomEvaluationContext $context, VacationPeriod $vacation, HvacMode $mode): ?PresetKey
    {
        $schedule = $context->schedule;
        if (null === $schedule || $schedule->mode !== $mode) {
            return null;
        }

        return $schedule->activeSlotAt($vacation->returnAt, $context->anchors)->presetKey();
    }

    private function leadSeconds(RoomEvaluationContext $context, HvacMode $mode, ?PresetKey $presetAtReturn): int
    {
        if (!$context->room->preheatEnabled || null === $presetAtReturn) {
            return 0;
        }

        return PreheatLead::seconds(
            $mode,
            $context->room->presetTemperatures->for($presetAtReturn, $mode),
            $context->room->presetTemperatures->for($context->policy->absentPreset, $mode),
            $context->facts->currentTemperature,
            $context->facts->heatingRatePerHour,
            $context->room->preheatMaxMinutes,
        );
    }

    private function raisedToReturnTarget(RoomEvaluationContext $context, VacationPeriod $vacation, HvacMode $mode, ?PresetKey $presetAtReturn, Decision $decision): Decision
    {
        if (null === $presetAtReturn || null === $decision->target) {
            return $decision;
        }

        $atReturn = $context->room->presetTemperatures->for($presetAtReturn, $mode);
        if (!PreheatLead::isHarder($mode, $decision->target, $atReturn)) {
            return $decision;
        }

        return new Decision(
            $mode,
            $atReturn,
            new Reason('reason.preheat', ['preset' => (string) $presetAtReturn, 'at' => $vacation->returnAt->format(\DATE_ATOM)]),
            $decision->reevaluateAt,
        );
    }
}
