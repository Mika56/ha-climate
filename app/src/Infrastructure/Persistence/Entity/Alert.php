<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use App\Infrastructure\Persistence\Repository\AlertRepository;
use App\Infrastructure\Persistence\Support\Guard;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AlertRepository::class)]
#[ORM\Table(name: 'alert')]
#[ORM\UniqueConstraint(name: 'uniq_alert_key', columns: ['alert_key'])]
#[ORM\Index(name: 'idx_alert_resolved_at', columns: ['resolved_at'])]
final class Alert
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(name: 'alert_key', type: 'string', length: 190)]
    private string $key;

    #[ORM\Column(type: 'string', length: 64)]
    private string $type;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $entityId;

    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Room $room;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $openedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $notifiedAt = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $persistentNotificationId = null;

    public function __construct(string $key, string $type, \DateTimeImmutable $openedAt, ?string $entityId = null, ?Room $room = null)
    {
        $this->id = Uuid::v7();
        $this->key = Guard::notBlank($key, 'key');
        $this->type = Guard::notBlank($type, 'type');
        $this->openedAt = $openedAt;
        $this->entityId = Guard::nullableNotBlank($entityId, 'entityId');
        $this->room = $room;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getEntityId(): ?string
    {
        return $this->entityId;
    }

    public function getRoom(): ?Room
    {
        return $this->room;
    }

    public function getOpenedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->notifiedAt;
    }

    public function getPersistentNotificationId(): ?string
    {
        return $this->persistentNotificationId;
    }

    public function isActive(): bool
    {
        return null === $this->resolvedAt;
    }

    public function resolve(\DateTimeImmutable $resolvedAt): void
    {
        if ($resolvedAt < $this->openedAt) {
            throw new \InvalidArgumentException('An alert cannot be resolved before it was opened.');
        }
        $this->resolvedAt ??= $resolvedAt;
    }

    /**
     * Reopens a previously resolved alert (the same key flaps) and clears its notification state.
     */
    public function reopen(\DateTimeImmutable $openedAt): void
    {
        $this->openedAt = $openedAt;
        $this->resolvedAt = null;
        $this->notifiedAt = null;
        $this->persistentNotificationId = null;
    }

    public function markNotified(\DateTimeImmutable $notifiedAt): void
    {
        $this->notifiedAt = $notifiedAt;
    }

    public function attachPersistentNotification(?string $persistentNotificationId): void
    {
        $this->persistentNotificationId = Guard::nullableNotBlank($persistentNotificationId, 'persistentNotificationId');
    }
}
