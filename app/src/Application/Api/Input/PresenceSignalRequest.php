<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

use App\Domain\Room\PresenceSignal;
use App\Domain\Room\PresenceSignalKind;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One presence signal of a room (`PresenceSignal` of the contract). What depends on the kind (domain of the
 * entity, threshold, value) is checked by {@see \App\Application\Api\Room\PresenceSignalsChecker}.
 */
final readonly class PresenceSignalRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: EntityIdPattern::ENTITY_ID)]
        public string $entityId,
        public PresenceSignalKind $kind,
        public ?float $threshold = null,
        #[Assert\Length(max: PresenceSignal::MAX_VALUE_LENGTH)]
        public ?string $value = null,
    ) {
    }
}
