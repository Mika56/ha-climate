<?php

declare(strict_types=1);

namespace App\Application\Api\Schedule;

use App\Application\Api\Exception\Violations;
use App\Application\Api\Input\ScheduleRequest;
use App\Application\Api\Input\SlotStartRequest;
use App\Infrastructure\Persistence\Entity\Preset;
use App\Infrastructure\Persistence\Entity\Schedule;
use App\Infrastructure\Persistence\Enum\SlotStartType;

/**
 * Fills a schedule entity with the slots of a request, after checking that every slot points at a known preset.
 */
final readonly class ScheduleBuilder
{
    /**
     * @param array<string, Preset> $presets every preset by key
     */
    public function checkPresets(ScheduleRequest $request, array $presets, Violations $violations, string $pathPrefix = ''): void
    {
        foreach ($request->slots as $index => $slot) {
            if (!isset($presets[$slot->preset])) {
                $violations->add(\sprintf('%sslots[%d].preset', $pathPrefix, $index), 'api.unknown_preset', ['preset' => $slot->preset]);
            }
        }
    }

    /**
     * Replaces the slots of the schedule. Call {@see checkPresets()} first.
     *
     * @param array<string, Preset> $presets
     */
    public function fill(Schedule $schedule, ScheduleRequest $request, array $presets): void
    {
        $schedule->clearSlots();
        foreach ($request->slots as $slot) {
            $start = $slot->start;
            if (SlotStartRequest::ANCHORED === $start->type) {
                $fallback = $start->fallbackMinuteOfDay ?? 0;
                $schedule->addSlot($slot->weekday, SlotStartType::Anchored, $fallback, $presets[$slot->preset], $start->entityId, $start->offsetMinutes, $fallback);
            } else {
                $schedule->addSlot($slot->weekday, SlotStartType::Fixed, $start->minuteOfDay ?? 0, $presets[$slot->preset]);
            }
        }
    }
}
