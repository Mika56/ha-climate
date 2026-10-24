<?php

declare(strict_types=1);

namespace App\Application\Port\HomeAssistant;

/**
 * Home Assistant "context" attached to a state change or a service call.
 *
 * The engine remembers the ids of the contexts created by its own commands to tell them apart from
 * manual changes (a context with a user id, or an unknown id, is not ours).
 */
final readonly class StateContext
{
    public function __construct(
        public string $id,
        public ?string $parentId = null,
        public ?string $userId = null,
    ) {
    }
}
