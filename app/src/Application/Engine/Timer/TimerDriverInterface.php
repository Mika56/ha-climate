<?php

declare(strict_types=1);

namespace App\Application\Engine\Timer;

/**
 * Abstraction of "call me back in N seconds": the Revolt event loop in production, a manually advanced
 * driver in tests. It knows nothing about persistence or about what the callback does.
 */
interface TimerDriverInterface
{
    /**
     * @param \Closure(): void $callback called once, at least $seconds from now (0 means as soon as possible)
     *
     * @return string an opaque handle for {@see cancel()}
     */
    public function delay(float $seconds, \Closure $callback): string;

    /**
     * Cancels a pending delay. Harmless when it already fired or never existed.
     */
    public function cancel(string $handle): void;
}
