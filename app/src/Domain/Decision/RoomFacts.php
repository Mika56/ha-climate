<?php

declare(strict_types=1);

namespace App\Domain\Decision;

use App\Domain\Climate\Temperature;

/**
 * Snapshot of the runtime facts known about a room. Every fact is optional.
 */
final readonly class RoomFacts
{
    public function __construct(
        public ?\DateTimeImmutable $houseEmptySince = null,
        public ?\DateTimeImmutable $roomVacantSince = null,
        public ?\DateTimeImmutable $openingOpenSince = null,
        public ?ActiveOverride $override = null,
        public ?VacationPeriod $vacation = null,
        public ?Temperature $currentTemperature = null,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }
}
