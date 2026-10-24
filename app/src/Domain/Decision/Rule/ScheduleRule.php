<?php

declare(strict_types=1);

namespace App\Domain\Decision\Rule;

use App\Domain\Climate\HvacMode;
use App\Domain\Decision\Decision;
use App\Domain\Decision\Reason;
use App\Domain\Decision\RoomEvaluationContext;
use App\Domain\Decision\RoomRuleInterface;

/**
 * Terminal rule: applies the preset of the schedule slot currently in force.
 */
final readonly class ScheduleRule implements RoomRuleInterface
{
    public static function priority(): int
    {
        return -1000;
    }

    public function apply(RoomEvaluationContext $context, callable $next): Decision
    {
        $mode = $context->mode();
        $schedule = $context->schedule;

        if (HvacMode::Off === $mode || null === $schedule || $schedule->mode !== $mode) {
            return Decision::off(new Reason('reason.no_schedule'));
        }

        $active = $schedule->activeSlotAt($context->now, $context->anchors);
        $following = $schedule->nextSlotAfter($context->now, $context->anchors);
        $preset = $active->presetKey();
        $target = $context->room->presetTemperatures->for($preset, $mode);
        $reason = new Reason('reason.schedule', [
            'preset' => (string) $preset,
            'until' => $following->start->format('H:i'),
        ]);

        return HvacMode::Heat === $mode ? Decision::heat($target, $reason) : Decision::cool($target, $reason);
    }
}
