<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class PresetTemperatureRequest
{
    public function __construct(
        #[Assert\Regex(pattern: EntityIdPattern::PRESET_KEY)]
        public string $preset,
        #[Assert\Range(min: 5, max: 35)]
        public ?float $heat = null,
        #[Assert\Range(min: 5, max: 35)]
        public ?float $cool = null,
    ) {
    }
}
