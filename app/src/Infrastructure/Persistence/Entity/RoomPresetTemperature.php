<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use App\Domain\Climate\HvacMode;
use App\Infrastructure\Persistence\Support\Guard;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'room_preset_temperature')]
#[ORM\UniqueConstraint(name: 'uniq_room_preset_mode', columns: ['room_id', 'preset_id', 'mode'])]
final class RoomPresetTemperature
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Room::class, inversedBy: 'presetTemperatures')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Room $room;

    #[ORM\ManyToOne(targetEntity: Preset::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Preset $preset;

    #[ORM\Column(type: 'string', length: 8, enumType: HvacMode::class)]
    private HvacMode $mode;

    #[ORM\Column(type: 'float')]
    private float $temperature;

    /**
     * Built through {@see Room::setPresetTemperature()}.
     *
     * @internal
     */
    public function __construct(Room $room, Preset $preset, HvacMode $mode, float $temperature)
    {
        $this->id = Uuid::v7();
        $this->room = $room;
        $this->preset = $preset;
        $this->mode = Guard::activeMode($mode, 'mode');
        $this->temperature = $temperature;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRoom(): Room
    {
        return $this->room;
    }

    public function getPreset(): Preset
    {
        return $this->preset;
    }

    public function getMode(): HvacMode
    {
        return $this->mode;
    }

    public function getTemperature(): float
    {
        return $this->temperature;
    }

    public function changeTemperature(float $temperature): void
    {
        $this->temperature = $temperature;
    }
}
