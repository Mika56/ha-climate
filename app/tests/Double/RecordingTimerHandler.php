<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Application\Engine\Timer\TimerHandlerInterface;
use App\Infrastructure\Persistence\Entity\Timer;

/**
 * Timer handler for tests: records the timers it receives and optionally runs a callback.
 */
final class RecordingTimerHandler implements TimerHandlerInterface
{
    /** @var list<string> keys of the handled timers */
    public array $handled = [];

    /**
     * @param (\Closure(Timer): void)|null $onHandle
     */
    public function __construct(
        private readonly string $type = 'test_timer',
        private readonly ?\Closure $onHandle = null,
    ) {
    }

    public function type(): string
    {
        return $this->type;
    }

    public function handle(Timer $timer): void
    {
        $this->handled[] = $timer->getKey();
        if (null !== $this->onHandle) {
            ($this->onHandle)($timer);
        }
    }
}
