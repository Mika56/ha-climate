<?php

declare(strict_types=1);

namespace App\Application\Engine\Command;

use App\Domain\Climate\HvacMode;
use App\Domain\HomeAssistant\EntityId;
use App\Domain\Room\RoomId;

/**
 * Dispatched (Symfony EventDispatcher) by {@see CommandExecutor} after Home Assistant accepted a command
 * to a device. `temperature` is null when the command only set the mode.
 */
final readonly class DeviceCommandSent
{
    public function __construct(
        public RoomId $roomId,
        public EntityId $device,
        public HvacMode $mode,
        public ?float $temperature,
        public \DateTimeImmutable $sentAt,
        public string $contextId,
    ) {
    }
}
