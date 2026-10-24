<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Enum\ActorType;
use App\Infrastructure\Persistence\Support\Guard;

/**
 * Filters and pagination of an audit search; bounds are inclusive.
 */
final readonly class AuditSearchCriteria
{
    public const int DEFAULT_LIMIT = 50;

    public int $limit;
    public int $offset;

    public function __construct(
        public ?Room $room = null,
        public ?ActorType $actorType = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0,
    ) {
        $this->limit = Guard::positiveInt($limit, 'limit');
        $this->offset = Guard::nonNegativeInt($offset, 'offset');
    }
}
