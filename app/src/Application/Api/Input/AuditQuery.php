<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

use App\Infrastructure\Persistence\Enum\ActorType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Filters and paging of the audit trail (query string). The CSV export reads the same filters and ignores the
 * paging.
 */
final readonly class AuditQuery
{
    public function __construct(
        #[Assert\Uuid]
        public ?string $roomId = null,
        public ?ActorType $actorType = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        #[Assert\Range(min: 1, max: 200)]
        public int $limit = 50,
        #[Assert\PositiveOrZero]
        public int $offset = 0,
    ) {
    }
}
