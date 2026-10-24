<?php

declare(strict_types=1);

namespace App\Application\Engine\Lifecycle;

/**
 * Blocks the engine's main flow until the process is asked to stop (SIGTERM or SIGINT).
 */
interface ShutdownWaiterInterface
{
    public function waitForShutdown(): void;
}
