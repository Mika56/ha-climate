<?php

declare(strict_types=1);

namespace App\Application\Api\Schedule;

use App\Infrastructure\Persistence\Entity\Schedule;
use App\Infrastructure\Persistence\Entity\ScheduleSlot;
use App\Infrastructure\Persistence\Enum\SlotStartType;

/**
 * A schedule as the API shows it.
 */
final readonly class ScheduleNormalizer
{
    /**
     * The `ScheduleInput` fields: what an update replaces and what a backup keeps.
     *
     * @return array{name: string, mode: string, slots: list<array<string, mixed>>}
     */
    public function input(Schedule $schedule): array
    {
        return [
            'name' => $schedule->getName(),
            'mode' => $schedule->getMode()->value,
            'slots' => array_values(array_map($this->slot(...), $schedule->getSlots()->toArray())),
        ];
    }

    /**
     * @param list<array{roomId: string, roomName: string}> $usedBy
     *
     * @return array<string, mixed>
     */
    public function normalize(Schedule $schedule, array $usedBy): array
    {
        return ['id' => $schedule->getId()->toRfc4122(), ...$this->input($schedule), 'usedBy' => $usedBy];
    }

    /**
     * @return array<string, mixed>
     */
    private function slot(ScheduleSlot $slot): array
    {
        $start = SlotStartType::Anchored === $slot->getStartType()
            ? [
                'type' => 'anchored',
                'entityId' => $slot->getAnchorEntityId(),
                'offsetMinutes' => $slot->getOffsetMinutes(),
                'fallbackMinuteOfDay' => $slot->getFallbackMinuteOfDay(),
            ]
            : ['type' => 'fixed', 'minuteOfDay' => $slot->getMinuteOfDay()];

        return ['weekday' => $slot->getWeekday(), 'start' => $start, 'preset' => $slot->getPreset()->getKey()];
    }
}
