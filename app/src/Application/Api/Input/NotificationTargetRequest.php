<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class NotificationTargetRequest
{
    public function __construct(
        #[Assert\Regex(pattern: EntityIdPattern::NOTIFY_SERVICE)]
        public string $service,
        public bool $enabled,
    ) {
    }
}
