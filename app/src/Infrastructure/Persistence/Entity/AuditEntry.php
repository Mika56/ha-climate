<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use App\Infrastructure\Persistence\Enum\ActorType;
use App\Infrastructure\Persistence\Repository\AuditEntryRepository;
use App\Infrastructure\Persistence\Support\Guard;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AuditEntryRepository::class)]
#[ORM\Table(name: 'audit_entry')]
#[ORM\Index(name: 'idx_audit_occurred_at', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_audit_room_occurred_at', columns: ['room_id', 'occurred_at'])]
final class AuditEntry
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: 'string', length: 16, enumType: ActorType::class)]
    private ActorType $actorType;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $actorId;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $actorName;

    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Room $room;

    /**
     * Snapshot of the room name, kept when the room is deleted.
     */
    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $roomName;

    #[ORM\Column(type: 'string', length: 64)]
    private string $category;

    #[ORM\Column(type: 'string', length: 255)]
    private string $messageKey;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $params;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $payload;

    /**
     * @param array<string, mixed> $params  translation parameters of the message key
     * @param array<string, mixed> $payload machine-readable details (e.g. before/after)
     */
    public function __construct(
        \DateTimeImmutable $occurredAt,
        ActorType $actorType,
        string $category,
        string $messageKey,
        array $params = [],
        array $payload = [],
        ?string $actorId = null,
        ?string $actorName = null,
        ?Room $room = null,
    ) {
        $this->id = Uuid::v7();
        $this->occurredAt = $occurredAt;
        $this->actorType = $actorType;
        $this->actorId = $actorId;
        $this->actorName = $actorName;
        $this->room = $room;
        $this->roomName = $room?->getName();
        $this->category = Guard::notBlank($category, 'category');
        $this->messageKey = Guard::notBlank($messageKey, 'messageKey');
        $this->params = $params;
        $this->payload = $payload;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getActorType(): ActorType
    {
        return $this->actorType;
    }

    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    public function getActorName(): ?string
    {
        return $this->actorName;
    }

    public function getRoom(): ?Room
    {
        return $this->room;
    }

    public function getRoomName(): ?string
    {
        return $this->roomName;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
