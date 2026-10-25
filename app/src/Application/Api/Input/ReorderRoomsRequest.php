<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ReorderRoomsRequest
{
    /**
     * @param list<string> $roomIds
     */
    public function __construct(
        #[Assert\All([new Assert\Uuid()])]
        public array $roomIds,
    ) {
    }
}
