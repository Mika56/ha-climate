<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use App\Infrastructure\Persistence\Enum\NotificationType;
use App\Infrastructure\Persistence\Repository\NotificationTypeSettingRepository;
use App\Infrastructure\Persistence\Support\Guard;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: NotificationTypeSettingRepository::class)]
#[ORM\Table(name: 'notification_type_setting')]
#[ORM\UniqueConstraint(name: 'uniq_notification_type', columns: ['type'])]
final class NotificationTypeSetting
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: 'string', length: 32, enumType: NotificationType::class)]
    private NotificationType $type;

    #[ORM\Column(type: 'boolean')]
    private bool $enabled;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $delaySeconds;

    #[ORM\Column(type: 'boolean')]
    private bool $persistentNotification;

    public function __construct(NotificationType $type, bool $enabled = true, ?int $delaySeconds = null, bool $persistentNotification = false)
    {
        $this->id = Uuid::v7();
        $this->type = $type;
        $this->enabled = $enabled;
        $this->delaySeconds = null === $delaySeconds ? null : Guard::nonNegativeInt($delaySeconds, 'delaySeconds');
        $this->persistentNotification = $persistentNotification;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getType(): NotificationType
    {
        return $this->type;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getDelaySeconds(): ?int
    {
        return $this->delaySeconds;
    }

    public function isPersistentNotification(): bool
    {
        return $this->persistentNotification;
    }

    public function configure(bool $enabled, ?int $delaySeconds, bool $persistentNotification): void
    {
        $this->enabled = $enabled;
        $this->delaySeconds = null === $delaySeconds ? null : Guard::nonNegativeInt($delaySeconds, 'delaySeconds');
        $this->persistentNotification = $persistentNotification;
    }
}
