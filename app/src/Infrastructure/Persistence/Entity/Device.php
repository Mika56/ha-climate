<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use App\Infrastructure\Persistence\Enum\DeviceRole;
use App\Infrastructure\Persistence\Support\Guard;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'device')]
#[ORM\UniqueConstraint(name: 'uniq_device_entity_id', columns: ['entity_id'])]
final class Device
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Room::class, inversedBy: 'devices')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Room $room;

    #[ORM\Column(type: 'string', length: 255)]
    private string $entityId;

    #[ORM\Column(type: 'string', length: 16, enumType: DeviceRole::class)]
    private DeviceRole $role;

    #[ORM\Column(type: 'integer')]
    private int $minOnSeconds;

    #[ORM\Column(type: 'integer')]
    private int $minOffSeconds;

    #[ORM\Column(type: 'float')]
    private float $hysteresis;

    /**
     * Built through {@see Room::addDevice()}.
     *
     * @internal
     */
    public function __construct(Room $room, string $entityId, DeviceRole $role, int $minOnSeconds = 0, int $minOffSeconds = 0, float $hysteresis = 0.5)
    {
        $this->id = Uuid::v7();
        $this->room = $room;
        $this->entityId = Guard::notBlank($entityId, 'entityId');
        $this->role = $role;
        $this->minOnSeconds = Guard::nonNegativeInt($minOnSeconds, 'minOnSeconds');
        $this->minOffSeconds = Guard::nonNegativeInt($minOffSeconds, 'minOffSeconds');
        $this->hysteresis = Guard::nonNegativeFloat($hysteresis, 'hysteresis');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRoom(): Room
    {
        return $this->room;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function getRole(): DeviceRole
    {
        return $this->role;
    }

    public function getMinOnSeconds(): int
    {
        return $this->minOnSeconds;
    }

    public function getMinOffSeconds(): int
    {
        return $this->minOffSeconds;
    }

    public function getHysteresis(): float
    {
        return $this->hysteresis;
    }

    public function changeRole(DeviceRole $role): void
    {
        $this->role = $role;
    }

    public function configureAntiCycling(int $minOnSeconds, int $minOffSeconds, float $hysteresis): void
    {
        $this->minOnSeconds = Guard::nonNegativeInt($minOnSeconds, 'minOnSeconds');
        $this->minOffSeconds = Guard::nonNegativeInt($minOffSeconds, 'minOffSeconds');
        $this->hysteresis = Guard::nonNegativeFloat($hysteresis, 'hysteresis');
    }
}
