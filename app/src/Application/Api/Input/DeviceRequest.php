<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

use App\Domain\Device\DeviceRole;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class DeviceRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: EntityIdPattern::ENTITY_ID)]
        public string $entityId,
        public DeviceRole $role,
        #[Assert\Range(min: 0, max: 7200)]
        public int $minOnSeconds = 0,
        #[Assert\Range(min: 0, max: 7200)]
        public int $minOffSeconds = 0,
        #[Assert\Range(min: 0, max: 2)]
        public float $hysteresis = 0.5,
    ) {
    }
}
