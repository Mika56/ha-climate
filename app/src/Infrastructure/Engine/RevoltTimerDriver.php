<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine;

use App\Application\Engine\Timer\TimerDriverInterface;
use Revolt\EventLoop;

/**
 * Production timer driver: one Revolt event loop delay per timer.
 */
final readonly class RevoltTimerDriver implements TimerDriverInterface
{
    public function delay(float $seconds, \Closure $callback): string
    {
        return EventLoop::delay(max(0.0, $seconds), static function () use ($callback): void {
            $callback();
        });
    }

    public function cancel(string $handle): void
    {
        EventLoop::cancel($handle);
    }
}
