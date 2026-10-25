<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class PresetRequest
{
    public function __construct(
        #[Assert\Regex(pattern: EntityIdPattern::PRESET_KEY)]
        public string $key,
        #[Assert\NotBlank]
        #[Assert\Length(max: 32)]
        public string $label,
        #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/D')]
        public string $color,
        #[Assert\PositiveOrZero]
        public int $position = 0,
    ) {
    }
}
