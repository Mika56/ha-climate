<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SlotRequest
{
    public function __construct(
        #[Assert\Range(min: 1, max: 7)]
        public int $weekday,
        #[Assert\Valid]
        public SlotStartRequest $start,
        #[Assert\Regex(pattern: EntityIdPattern::PRESET_KEY)]
        public string $preset,
    ) {
    }
}
