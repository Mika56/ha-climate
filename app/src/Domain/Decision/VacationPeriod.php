<?php

declare(strict_types=1);

namespace App\Domain\Decision;

use App\Domain\Exception\InvalidValueException;

final readonly class VacationPeriod
{
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $returnAt,
        public bool $offDuringVacation,
    ) {
        if ($returnAt <= $start) {
            throw new InvalidValueException('Vacation return must be after its start.');
        }
    }

    public function isActiveAt(\DateTimeImmutable $now): bool
    {
        return $now >= $this->start && $now < $this->returnAt;
    }
}
