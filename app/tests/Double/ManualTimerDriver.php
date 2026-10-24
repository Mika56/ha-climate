<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Application\Engine\Timer\TimerDriverInterface;
use Symfony\Component\Clock\MockClock;

/**
 * Timer driver for tests: nothing fires by itself, time moves only through {@see advance()}, which moves the
 * shared mock clock forward and runs the callbacks that come due, in order, each at its own instant.
 */
final class ManualTimerDriver implements TimerDriverInterface
{
    /** @var array<string, array{due: float, callback: \Closure(): void}> */
    private array $pending = [];

    private int $counter = 0;

    public function __construct(private readonly MockClock $clock)
    {
    }

    public function delay(float $seconds, \Closure $callback): string
    {
        $handle = 'timer-'.++$this->counter;
        $this->pending[$handle] = ['due' => (float) $this->clock->now()->format('U.u') + $seconds, 'callback' => $callback];

        return $handle;
    }

    public function cancel(string $handle): void
    {
        unset($this->pending[$handle]);
    }

    public function pendingCount(): int
    {
        return \count($this->pending);
    }

    /**
     * Fires the earliest pending timer right now, whatever the time: a driver waking up too early.
     */
    public function fireEarly(): bool
    {
        $handle = $this->nextDue(\PHP_FLOAT_MAX);
        if (null === $handle) {
            return false;
        }

        $callback = $this->pending[$handle]['callback'];
        unset($this->pending[$handle]);
        $callback();

        return true;
    }

    /**
     * Moves time forward by $seconds, firing every timer that comes due on the way.
     */
    public function advance(float $seconds): void
    {
        $target = (float) $this->clock->now()->format('U.u') + $seconds;

        while (null !== ($handle = $this->nextDue($target))) {
            $timer = $this->pending[$handle];
            unset($this->pending[$handle]);

            $now = (float) $this->clock->now()->format('U.u');
            if ($timer['due'] > $now) {
                $this->clock->sleep($timer['due'] - $now);
            }
            ($timer['callback'])();
        }

        $now = (float) $this->clock->now()->format('U.u');
        if ($target > $now) {
            $this->clock->sleep($target - $now);
        }
    }

    private function nextDue(float $limit): ?string
    {
        $next = null;
        $nextDue = $limit;
        foreach ($this->pending as $handle => $timer) {
            if ($timer['due'] <= $nextDue) {
                $next = $handle;
                $nextDue = $timer['due'];
            }
        }

        return $next;
    }
}
