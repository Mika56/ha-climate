<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

use App\Infrastructure\Persistence\Enum\NotificationType;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class NotificationTypeRequest
{
    public function __construct(
        public NotificationType $type,
        public bool $enabled,
        #[Assert\Range(min: 0, max: 86400)]
        public ?int $delaySeconds = null,
        public bool $persistentNotification = false,
    ) {
    }
}
