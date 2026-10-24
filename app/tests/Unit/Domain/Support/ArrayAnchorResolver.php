<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Support;

use App\Domain\HomeAssistant\EntityId;
use App\Domain\Schedule\TimeAnchorResolverInterface;

/**
 * In-memory anchor resolver: unknown anchors resolve to null.
 */
final readonly class ArrayAnchorResolver implements TimeAnchorResolverInterface
{
    /**
     * @param array<string, int> $minutes minute of day indexed by entity id
     */
    public function __construct(private array $minutes = [])
    {
    }

    public function minuteOfDay(EntityId $anchor): ?int
    {
        return $this->minutes[(string) $anchor] ?? null;
    }
}
