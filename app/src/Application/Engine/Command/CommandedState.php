<?php

declare(strict_types=1);

namespace App\Application\Engine\Command;

use App\Domain\Climate\HvacMode;

/**
 * What the engine last asked a device to be, and when. The temperature is null when the command only set the mode;
 * `sentAt` is null for a state merely observed (a manual change taken as the new reference).
 */
final readonly class CommandedState
{
    public function __construct(
        public HvacMode $mode,
        public ?float $temperature,
        public ?\DateTimeImmutable $sentAt = null,
    ) {
    }
}
