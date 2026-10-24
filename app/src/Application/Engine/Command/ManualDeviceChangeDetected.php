<?php

declare(strict_types=1);

namespace App\Application\Engine\Command;

use App\Domain\HomeAssistant\EntityId;
use App\Domain\Room\RoomId;

/**
 * Dispatched (Symfony EventDispatcher) when a device of a room changed its mode or target temperature and
 * the change does not come from the engine: somebody used the device, the Home Assistant UI or an
 * automation.
 *
 * `userId` is the Home Assistant user behind the change; null means it happened on the device itself (or
 * from a system context). `old*` are the values observed just before, null when unknown.
 */
final readonly class ManualDeviceChangeDetected
{
    public function __construct(
        public RoomId $roomId,
        public EntityId $device,
        public ?string $oldMode,
        public ?float $oldTemperature,
        public string $newMode,
        public ?float $newTemperature,
        public ?string $userId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
