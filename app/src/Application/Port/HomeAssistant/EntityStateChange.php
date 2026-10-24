<?php

declare(strict_types=1);

namespace App\Application\Port\HomeAssistant;

/**
 * A change of one entity as seen by a subscriber.
 *
 * `old` is null when the entity was not known before (first state, entity added), `new` is null when
 * the entity was removed. Both are never null at the same time.
 */
final readonly class EntityStateChange
{
    public function __construct(
        public string $entityId,
        public ?EntityState $old,
        public ?EntityState $new,
    ) {
    }

    public function isRemoval(): bool
    {
        return null === $this->new;
    }
}
