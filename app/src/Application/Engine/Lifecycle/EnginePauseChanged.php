<?php

declare(strict_types=1);

namespace App\Application\Engine\Lifecycle;

/**
 * Dispatched (Symfony EventDispatcher) when HA Climate was paused or resumed, after the configuration was
 * reloaded and the rooms evaluated again: listeners drop what must not outlive a pause (persistent
 * notifications, device-unresponsive timers) or catch up with what was held back (pending notifications).
 */
final readonly class EnginePauseChanged
{
    public function __construct(public bool $paused)
    {
    }
}
