<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class DuplicateScheduleRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 64)]
        public string $name,
    ) {
    }
}
