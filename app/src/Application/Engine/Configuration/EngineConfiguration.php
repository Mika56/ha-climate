<?php

declare(strict_types=1);

namespace App\Application\Engine\Configuration;

use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Domain\Decision\GlobalPolicy;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\VacationPeriod;
use App\Domain\HomeAssistant\EntityId;
use App\Domain\Room\RoomId;
use App\Domain\Schedule\ScheduleId;
use App\Domain\Schedule\WeeklySchedule;

/**
 * Immutable picture of everything the engine reads from the database, expressed with domain objects.
 */
final readonly class EngineConfiguration
{
    /**
     * @param list<RoomConfiguration>       $rooms
     * @param array<string, WeeklySchedule> $schedules      indexed by schedule id
     * @param list<EntityId>                $trackedPersons
     */
    public function __construct(
        public array $rooms,
        public array $schedules,
        public GlobalPolicy $policy,
        public Season $season,
        public array $trackedPersons,
        public ?VacationPeriod $vacation,
        public ?EntityId $weatherEntity,
        public int $auditRetentionDays,
        public int $manualOverrideMinutes,
    ) {
    }

    /**
     * @return list<RoomId>
     */
    public function roomIds(): array
    {
        return array_map(static fn (RoomConfiguration $room): RoomId => $room->id, $this->rooms);
    }

    public function room(RoomId $id): ?RoomConfiguration
    {
        foreach ($this->rooms as $room) {
            if ($room->id->equals($id)) {
                return $room;
            }
        }

        return null;
    }

    /**
     * The room that owns the given device entity, if any.
     */
    public function roomOfDevice(EntityId $deviceEntity): ?RoomConfiguration
    {
        foreach ($this->rooms as $room) {
            foreach ($room->devices as $device) {
                if ($device->entityId->equals($deviceEntity)) {
                    return $room;
                }
            }
        }

        return null;
    }

    public function schedule(?ScheduleId $id): ?WeeklySchedule
    {
        return null === $id ? null : ($this->schedules[(string) $id] ?? null);
    }

    /**
     * The schedule the room follows in the given mode, if it has one that exists and fits the mode.
     */
    public function scheduleFor(RoomConfiguration $room, HvacMode $mode): ?WeeklySchedule
    {
        $schedule = $this->schedule($room->scheduleIdFor($mode));

        return null !== $schedule && $schedule->mode === $mode ? $schedule : null;
    }
}
