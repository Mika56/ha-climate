<?php

declare(strict_types=1);

namespace App\Application\Port\HomeAssistant;

final readonly class Area
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $floorId = null,
    ) {
    }
}
