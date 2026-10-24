<?php

declare(strict_types=1);

namespace App\Application\Engine\Command;

use App\Application\Port\HomeAssistant\EntityState;
use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Temperature;
use App\Domain\Device\Device;
use App\Domain\Exception\InvalidValueException;
use App\Domain\Room\RoomId;

/**
 * A climate command the engine is about to send because a device is not in the state the decision wants.
 * Command guards inspect it and may postpone it.
 */
final readonly class PlannedCommand
{
    public const string SERVICE_SET_TEMPERATURE = 'set_temperature';
    public const string SERVICE_SET_HVAC_MODE = 'set_hvac_mode';

    private function __construct(
        public RoomId $roomId,
        public Device $device,
        public HvacMode $mode,
        public ?Temperature $temperature,
        public EntityState $current,
    ) {
        if (HvacMode::Off !== $mode && null === $temperature) {
            throw new InvalidValueException('A command that turns a device on requires a target temperature.');
        }
        if (HvacMode::Off === $mode && null !== $temperature) {
            throw new InvalidValueException('A command that turns a device off has no target temperature.');
        }
    }

    public static function turnOff(RoomId $roomId, Device $device, EntityState $current): self
    {
        return new self($roomId, $device, HvacMode::Off, null, $current);
    }

    public static function setTarget(RoomId $roomId, Device $device, HvacMode $mode, Temperature $temperature, EntityState $current): self
    {
        return new self($roomId, $device, $mode, $temperature, $current);
    }

    /**
     * Name of the `climate` service to call.
     */
    public function service(): string
    {
        return null === $this->temperature ? self::SERVICE_SET_HVAC_MODE : self::SERVICE_SET_TEMPERATURE;
    }

    /**
     * @return array<string, mixed>
     */
    public function serviceData(): array
    {
        if (null === $this->temperature) {
            return ['hvac_mode' => $this->mode->value];
        }

        return ['temperature' => $this->temperature->toFloat(), 'hvac_mode' => $this->mode->value];
    }

    /**
     * The device is currently off and the command makes it run.
     */
    public function isTurningOn(): bool
    {
        return HvacMode::Off !== $this->mode && 'off' === $this->current->state;
    }

    /**
     * The device is currently running (or in an unknown active mode) and the command stops it.
     */
    public function isTurningOff(): bool
    {
        return HvacMode::Off === $this->mode && 'off' !== $this->current->state;
    }
}
