<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use App\Domain\Climate\HvacMode;
use App\Infrastructure\Persistence\Enum\OverrideType;
use App\Infrastructure\Persistence\Repository\RoomOverrideRepository;
use App\Infrastructure\Persistence\Support\Guard;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: RoomOverrideRepository::class)]
#[ORM\Table(name: 'room_override')]
#[ORM\Index(name: 'idx_room_override_room', columns: ['room_id'])]
final class RoomOverride
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Room $room;

    #[ORM\Column(type: 'string', length: 16, enumType: OverrideType::class)]
    private OverrideType $type;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $delta;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $absoluteTarget;

    #[ORM\Column(type: 'string', length: 8, enumType: HvacMode::class)]
    private HvacMode $mode;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endsAt;

    #[ORM\Column(type: 'boolean')]
    private bool $untilNextSlot;

    #[ORM\Column(type: 'string', length: 128)]
    private string $actor;

    private function __construct(
        Room $room,
        OverrideType $type,
        ?float $delta,
        ?float $absoluteTarget,
        HvacMode $mode,
        \DateTimeImmutable $startedAt,
        ?\DateTimeImmutable $endsAt,
        bool $untilNextSlot,
        string $actor,
    ) {
        if (null !== $endsAt && $endsAt <= $startedAt) {
            throw new \InvalidArgumentException('An override must end after it started.');
        }

        $this->id = Uuid::v7();
        $this->room = $room;
        $this->type = $type;
        $this->delta = $delta;
        $this->absoluteTarget = $absoluteTarget;
        $this->mode = Guard::activeMode($mode, 'mode');
        $this->startedAt = $startedAt;
        $this->endsAt = $endsAt;
        $this->untilNextSlot = $untilNextSlot;
        $this->actor = Guard::notBlank($actor, 'actor');
    }

    /**
     * A boost raises (or lowers) the scheduled target by a delta, until a time or the next slot.
     */
    public static function boost(
        Room $room,
        HvacMode $mode,
        float $delta,
        \DateTimeImmutable $startedAt,
        ?\DateTimeImmutable $endsAt,
        bool $untilNextSlot,
        string $actor,
    ): self {
        if (null === $endsAt && !$untilNextSlot) {
            throw new \InvalidArgumentException('A boost needs an end date or must last until the next slot.');
        }

        return new self($room, OverrideType::Boost, $delta, null, $mode, $startedAt, $endsAt, $untilNextSlot, $actor);
    }

    /**
     * A manual override (a change made on the device itself) pins an absolute target.
     */
    public static function manual(
        Room $room,
        HvacMode $mode,
        float $absoluteTarget,
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $endsAt,
        string $actor,
    ): self {
        return new self($room, OverrideType::Manual, null, $absoluteTarget, $mode, $startedAt, $endsAt, false, $actor);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRoom(): Room
    {
        return $this->room;
    }

    public function getType(): OverrideType
    {
        return $this->type;
    }

    public function getDelta(): ?float
    {
        return $this->delta;
    }

    public function getAbsoluteTarget(): ?float
    {
        return $this->absoluteTarget;
    }

    public function getMode(): HvacMode
    {
        return $this->mode;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function isUntilNextSlot(): bool
    {
        return $this->untilNextSlot;
    }

    public function getActor(): string
    {
        return $this->actor;
    }

    /**
     * Whether the override is still in force at $now (an until-next-slot boost stays
     * active until the engine cancels it when the slot changes).
     */
    public function isActiveAt(\DateTimeImmutable $now): bool
    {
        if ($this->startedAt > $now) {
            return false;
        }

        return null === $this->endsAt || $this->endsAt > $now;
    }
}
