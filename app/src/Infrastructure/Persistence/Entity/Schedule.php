<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use App\Domain\Climate\HvacMode;
use App\Infrastructure\Persistence\Enum\SlotStartType;
use App\Infrastructure\Persistence\Repository\ScheduleRepository;
use App\Infrastructure\Persistence\Support\Guard;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ScheduleRepository::class)]
#[ORM\Table(name: 'schedule')]
final class Schedule
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: 'string', length: 128)]
    private string $name;

    #[ORM\Column(type: 'string', length: 8, enumType: HvacMode::class)]
    private HvacMode $mode;

    /**
     * @var Collection<int, ScheduleSlot>
     */
    #[ORM\OneToMany(targetEntity: ScheduleSlot::class, mappedBy: 'schedule', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['weekday' => 'ASC', 'minuteOfDay' => 'ASC'])]
    private Collection $slots;

    public function __construct(string $name, HvacMode $mode)
    {
        $this->id = Uuid::v7();
        $this->name = Guard::notBlank($name, 'name');
        $this->mode = Guard::activeMode($mode, 'mode');
        $this->slots = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = Guard::notBlank($name, 'name');
    }

    public function getMode(): HvacMode
    {
        return $this->mode;
    }

    /**
     * @return Collection<int, ScheduleSlot>
     */
    public function getSlots(): Collection
    {
        return $this->slots;
    }

    public function addSlot(
        int $weekday,
        SlotStartType $startType,
        int $minuteOfDay,
        Preset $preset,
        ?string $anchorEntityId = null,
        int $offsetMinutes = 0,
        ?int $fallbackMinuteOfDay = null,
    ): ScheduleSlot {
        $slot = new ScheduleSlot($this, $weekday, $startType, $minuteOfDay, $preset, $anchorEntityId, $offsetMinutes, $fallbackMinuteOfDay);
        $this->slots->add($slot);

        return $slot;
    }

    public function removeSlot(ScheduleSlot $slot): void
    {
        $this->slots->removeElement($slot);
    }

    public function clearSlots(): void
    {
        $this->slots->clear();
    }
}
