<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use App\Infrastructure\Persistence\Repository\PresetRepository;
use App\Infrastructure\Persistence\Support\Guard;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PresetRepository::class)]
#[ORM\Table(name: 'preset')]
#[ORM\UniqueConstraint(name: 'uniq_preset_key', columns: ['preset_key'])]
final class Preset
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(name: 'preset_key', type: 'string', length: 64)]
    private string $key;

    #[ORM\Column(type: 'string', length: 128)]
    private string $label;

    #[ORM\Column(type: 'string', length: 7)]
    private string $color;

    #[ORM\Column(type: 'integer')]
    private int $position;

    public function __construct(string $key, string $label, string $color, int $position = 0)
    {
        $this->id = Uuid::v7();
        $this->key = Guard::slug($key, 'key');
        $this->label = Guard::notBlank($label, 'label');
        $this->color = Guard::hexColor($color, 'color');
        $this->position = Guard::nonNegativeInt($position, 'position');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function describe(string $label, string $color): void
    {
        $this->label = Guard::notBlank($label, 'label');
        $this->color = Guard::hexColor($color, 'color');
    }

    public function moveTo(int $position): void
    {
        $this->position = Guard::nonNegativeInt($position, 'position');
    }
}
