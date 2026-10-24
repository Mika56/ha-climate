<?php

declare(strict_types=1);

namespace App\Application\Port\HomeAssistant;

final readonly class DeviceEntry
{
    public function __construct(
        public string $id,
        public ?string $name = null,
        public ?string $nameByUser = null,
        public ?string $areaId = null,
        public ?string $manufacturer = null,
        public ?string $model = null,
    ) {
    }

    public function displayName(): ?string
    {
        return $this->nameByUser ?? $this->name;
    }
}
