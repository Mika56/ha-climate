<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class NotificationSettingsRequest
{
    /**
     * @param list<NotificationTargetRequest> $targets
     * @param list<NotificationTypeRequest>   $types
     */
    public function __construct(
        #[Assert\Valid]
        public array $targets,
        #[Assert\Valid]
        public array $types,
    ) {
    }
}
