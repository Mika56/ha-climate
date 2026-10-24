<?php

declare(strict_types=1);

namespace App\Application\Engine\Lifecycle;

/**
 * Dispatched (Symfony EventDispatcher) when the state store was filled or extended without per-entity
 * change events: after the start of the engine and after a configuration reload. Listeners that keep
 * derived state (alerts, pending notifications) take a fresh look at everything.
 */
final readonly class EngineStateSynchronized
{
    public const string STARTUP = 'startup';
    public const string RELOAD = 'reload';

    public function __construct(public string $cause)
    {
    }
}
