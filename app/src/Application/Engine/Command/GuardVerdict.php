<?php

declare(strict_types=1);

namespace App\Application\Engine\Command;

use App\Domain\Decision\Reason;

/**
 * Answer of a {@see CommandGuardInterface}: let the command go, or postpone it until an instant.
 */
final readonly class GuardVerdict
{
    private function __construct(
        public ?\DateTimeImmutable $deferredUntil,
        public ?Reason $reason,
    ) {
    }

    public static function allow(): self
    {
        return new self(null, null);
    }

    public static function deferUntil(\DateTimeImmutable $until, Reason $reason): self
    {
        return new self($until, $reason);
    }

    public function isDeferred(): bool
    {
        return null !== $this->deferredUntil;
    }
}
