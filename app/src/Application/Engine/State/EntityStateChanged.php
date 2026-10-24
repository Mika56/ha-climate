<?php

declare(strict_types=1);

namespace App\Application\Engine\State;

use App\Application\Port\HomeAssistant\EntityStateChange;

/**
 * Dispatched (Symfony EventDispatcher) by {@see ChangeProcessor} for every change of a watched entity that
 * brought something new, once the state store holds the new state and the affected rooms were evaluated.
 */
final readonly class EntityStateChanged
{
    public function __construct(public EntityStateChange $change)
    {
    }
}
