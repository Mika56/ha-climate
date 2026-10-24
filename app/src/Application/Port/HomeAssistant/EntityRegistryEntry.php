<?php

declare(strict_types=1);

namespace App\Application\Port\HomeAssistant;

final readonly class EntityRegistryEntry
{
    public function __construct(
        public string $entityId,
        public ?string $deviceId = null,
        public ?string $areaId = null,
        public ?string $platform = null,
        public ?string $name = null,
        public ?string $originalName = null,
        public ?string $disabledBy = null,
        public ?string $hiddenBy = null,
    ) {
    }
}
