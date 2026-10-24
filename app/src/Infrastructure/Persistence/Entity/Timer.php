<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use App\Infrastructure\Persistence\Repository\TimerRepository;
use App\Infrastructure\Persistence\Support\Guard;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A pending engine timer, persisted so it survives restarts.
 */
#[ORM\Entity(repositoryClass: TimerRepository::class)]
#[ORM\Table(name: 'timer')]
#[ORM\UniqueConstraint(name: 'uniq_timer_key', columns: ['timer_key'])]
#[ORM\Index(name: 'idx_timer_due_at', columns: ['due_at'])]
final class Timer
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(name: 'timer_key', type: 'string', length: 190)]
    private string $key;

    #[ORM\Column(type: 'string', length: 64)]
    private string $type;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $roomId;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $dueAt;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $payload;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(string $key, string $type, \DateTimeImmutable $dueAt, ?Uuid $roomId = null, array $payload = [])
    {
        $this->id = Uuid::v7();
        $this->key = Guard::notBlank($key, 'key');
        $this->type = Guard::notBlank($type, 'type');
        $this->roomId = $roomId;
        $this->dueAt = $dueAt;
        $this->payload = $payload;
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

    public function getRoomId(): ?Uuid
    {
        return $this->roomId;
    }

    public function getDueAt(): \DateTimeImmutable
    {
        return $this->dueAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function reschedule(\DateTimeImmutable $dueAt, ?array $payload = null): void
    {
        $this->dueAt = $dueAt;
        if (null !== $payload) {
            $this->payload = $payload;
        }
    }

    public function isDueAt(\DateTimeImmutable $now): bool
    {
        return $this->dueAt <= $now;
    }
}
