<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine\Http;

/**
 * Optional companion of {@see InternalRouteInterface} for routes that keep responses open (streams): the
 * server cannot stop while one is still being written, so it asks them to end first.
 */
interface ShutdownAwareRouteInterface
{
    /**
     * Ends every response this route keeps open. Called once, just before the server stops.
     */
    public function shutdown(): void;
}
