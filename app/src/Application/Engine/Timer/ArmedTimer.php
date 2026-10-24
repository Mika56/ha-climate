<?php

declare(strict_types=1);

namespace App\Application\Engine\Timer;

/**
 * A timer currently waiting in the driver.
 *
 * @internal
 */
final readonly class ArmedTimer
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $handle,
        public \DateTimeImmutable $dueAt,
        public string $type,
        public ?string $roomId,
        public array $payload,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function isSameAs(\DateTimeImmutable $dueAt, string $type, ?string $roomId, array $payload): bool
    {
        return $this->dueAt->format('U.u') === $dueAt->format('U.u') && $this->type === $type && $this->roomId === $roomId && $this->payload === $payload;
    }
}
