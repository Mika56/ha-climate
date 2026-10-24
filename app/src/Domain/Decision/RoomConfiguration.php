<?php

declare(strict_types=1);

namespace App\Domain\Decision;

use App\Domain\Climate\HvacMode;
use App\Domain\Device\Device;
use App\Domain\Exception\InvalidValueException;
use App\Domain\HomeAssistant\EntityId;
use App\Domain\Room\PresetTemperatures;
use App\Domain\Room\RoomId;
use App\Domain\Schedule\ScheduleId;

/**
 * Static configuration of a room. Delays are expressed in seconds.
 */
final readonly class RoomConfiguration
{
    /**
     * @param list<Device>   $devices
     * @param list<EntityId> $openings
     */
    public function __construct(
        public RoomId $id,
        public string $name,
        public array $devices,
        public PresetTemperatures $presetTemperatures,
        public ?ScheduleId $heatingSchedule,
        public ?ScheduleId $coolingSchedule,
        public ?EntityId $temperatureSensor,
        public ?EntityId $presence,
        public array $openings,
        public int $vacancyDelay,
        public int $windowCutDelay,
        public int $windowNotifyDelay,
    ) {
        if ('' === trim($name)) {
            throw new InvalidValueException('Room name must not be empty.');
        }

        if ($vacancyDelay < 0 || $windowCutDelay < 0 || $windowNotifyDelay < 0) {
            throw new InvalidValueException('Room delays must not be negative.');
        }
    }

    public function hasDeviceFor(HvacMode $mode): bool
    {
        foreach ($this->devices as $device) {
            if ($device->role->canOperateIn($mode)) {
                return true;
            }
        }

        return false;
    }

    public function scheduleIdFor(HvacMode $mode): ?ScheduleId
    {
        return match ($mode) {
            HvacMode::Heat => $this->heatingSchedule,
            HvacMode::Cool => $this->coolingSchedule,
            HvacMode::Off => null,
        };
    }
}
