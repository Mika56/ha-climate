<?php

declare(strict_types=1);

namespace App\Application\Port\HomeAssistant;

/**
 * Outcome of a service call: the context Home Assistant created for it.
 */
final readonly class ServiceCallResult
{
    public function __construct(public StateContext $context)
    {
    }
}
