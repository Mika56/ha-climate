<?php

declare(strict_types=1);

namespace App\Domain\Room;

/**
 * What is known of a presence entity: its state and, when the signal is inactive, since when it has been.
 */
final readonly class PresenceReading
{
    public function __construct(public string $state, public \DateTimeImmutable $since)
    {
    }
}
