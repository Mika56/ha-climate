<?php

declare(strict_types=1);

namespace App\Domain\Device;

use App\Domain\HomeAssistant\EntityId;

final readonly class Device
{
    public function __construct(
        public EntityId $entityId,
        public DeviceRole $role,
        public AntiCyclingPolicy $antiCycling,
    ) {
    }
}
