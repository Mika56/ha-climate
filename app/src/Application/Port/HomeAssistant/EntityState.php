<?php

declare(strict_types=1);

namespace App\Application\Port\HomeAssistant;

/**
 * Full state of one entity at a given time.
 */
final readonly class EntityState
{
    /**
     * @param array<string, mixed> $attributes decoded JSON attributes, as sent by Home Assistant
     */
    public function __construct(
        public string $entityId,
        public string $state,
        public array $attributes,
        public \DateTimeImmutable $lastChanged,
        public \DateTimeImmutable $lastUpdated,
        public StateContext $context,
    ) {
    }
}
