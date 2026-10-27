<?php

declare(strict_types=1);

namespace App\Domain\Room;

/**
 * What Home Assistant says about a presence entity: its state and since when it has it.
 */
final readonly class PresenceReading
{
    public function __construct(public string $state, public \DateTimeImmutable $lastChanged)
    {
    }
}
