<?php

declare(strict_types=1);

namespace App\Application\Port\HomeAssistant;

final readonly class ServiceDescription
{
    /**
     * @param array<string, mixed> $fields service field schema as sent by Home Assistant
     */
    public function __construct(
        public string $domain,
        public string $service,
        public ?string $name = null,
        public ?string $description = null,
        public array $fields = [],
    ) {
    }
}
