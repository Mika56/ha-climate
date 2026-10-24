<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine;

use App\Application\Engine\Lifecycle\ShutdownWaiterInterface;
use Revolt\EventLoop;

/**
 * Waits for SIGTERM (Supervisor / s6 stop) or SIGINT (Ctrl+C) while the event loop keeps serving.
 */
final readonly class ShutdownSignals implements ShutdownWaiterInterface
{
    public function waitForShutdown(): void
    {
        $suspension = EventLoop::getSuspension();
        $handlers = [];
        foreach ([\SIGTERM, \SIGINT] as $signal) {
            $handlers[] = EventLoop::onSignal($signal, static function () use ($suspension): void {
                $suspension->resume();
            });
        }

        try {
            $suspension->suspend();
        } finally {
            foreach ($handlers as $handler) {
                EventLoop::cancel($handler);
            }
        }
    }
}
