<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Application\Engine\Lifecycle\ShutdownWaiterInterface;

/**
 * Shutdown waiter for tests: the "signal" has already arrived.
 */
final class ImmediateShutdown implements ShutdownWaiterInterface
{
    public int $waits = 0;

    public function waitForShutdown(): void
    {
        ++$this->waits;
    }
}
