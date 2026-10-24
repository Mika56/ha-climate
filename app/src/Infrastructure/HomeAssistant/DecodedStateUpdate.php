<?php

declare(strict_types=1);

namespace App\Infrastructure\HomeAssistant;

use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\EntityStateChange;

/**
 * Result of decoding one `subscribe_entities` event.
 */
final readonly class DecodedStateUpdate
{
    /**
     * @param list<EntityStateChange>    $changes changes to report to the subscriber, in order
     * @param array<string, EntityState> $cache   full state of every known entity after the event
     */
    public function __construct(
        public array $changes,
        public array $cache,
    ) {
    }
}
