<?php

declare(strict_types=1);

namespace App\Infrastructure\HomeAssistant;

/**
 * Exponential reconnection delays: 1 s, 2 s, 4 s ... capped at 60 s, reduced by up to 25 % of random jitter.
 */
final readonly class ReconnectBackoff
{
    private const float JITTER_RATIO = 0.25;

    /** @var \Closure(): float */
    private \Closure $jitter;

    /**
     * @param \Closure(): float|null $jitter returns a number in [0, 1); null uses a random one. Inject
     *                                       `static fn () => 0.0` for exact delays in tests
     */
    public function __construct(
        private float $initialDelay = 1.0,
        private float $maxDelay = 60.0,
        ?\Closure $jitter = null,
    ) {
        $this->jitter = $jitter ?? static fn (): float => random_int(0, 999) / 1000;
    }

    /**
     * @param int $attempt zero based
     */
    public function delayForAttempt(int $attempt): float
    {
        $base = min($this->maxDelay, $this->initialDelay * (2 ** min($attempt, 30)));

        return $base * (1 - self::JITTER_RATIO * ($this->jitter)());
    }
}
