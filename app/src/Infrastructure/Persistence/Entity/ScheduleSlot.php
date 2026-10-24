<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use App\Infrastructure\Persistence\Enum\SlotStartType;
use App\Infrastructure\Persistence\Support\Guard;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'schedule_slot')]
final class ScheduleSlot
{
    private const int MINUTES_PER_DAY = 1440;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Schedule::class, inversedBy: 'slots')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Schedule $schedule;

    #[ORM\Column(type: 'smallint')]
    private int $weekday;

    #[ORM\Column(type: 'string', length: 16, enumType: SlotStartType::class)]
    private SlotStartType $startType;

    #[ORM\Column(type: 'smallint')]
    private int $minuteOfDay;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $anchorEntityId;

    #[ORM\Column(type: 'smallint')]
    private int $offsetMinutes;

    #[ORM\Column(type: 'smallint')]
    private int $fallbackMinuteOfDay;

    #[ORM\ManyToOne(targetEntity: Preset::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Preset $preset;

    /**
     * Built through {@see Schedule::addSlot()}.
     *
     * @internal
     */
    public function __construct(
        Schedule $schedule,
        int $weekday,
        SlotStartType $startType,
        int $minuteOfDay,
        Preset $preset,
        ?string $anchorEntityId = null,
        int $offsetMinutes = 0,
        ?int $fallbackMinuteOfDay = null,
    ) {
        if (SlotStartType::Anchored === $startType && null === Guard::nullableNotBlank($anchorEntityId, 'anchorEntityId')) {
            throw new \InvalidArgumentException('An anchored slot requires an anchor entity id.');
        }
        if (SlotStartType::Fixed === $startType) {
            $anchorEntityId = null;
            $offsetMinutes = 0;
        }

        $this->id = Uuid::v7();
        $this->schedule = $schedule;
        $this->weekday = Guard::between($weekday, 1, 7, 'weekday');
        $this->startType = $startType;
        $this->minuteOfDay = Guard::between($minuteOfDay, 0, self::MINUTES_PER_DAY - 1, 'minuteOfDay');
        $this->anchorEntityId = $anchorEntityId;
        $this->offsetMinutes = Guard::between($offsetMinutes, -self::MINUTES_PER_DAY, self::MINUTES_PER_DAY, 'offsetMinutes');
        $this->fallbackMinuteOfDay = Guard::between($fallbackMinuteOfDay ?? $minuteOfDay, 0, self::MINUTES_PER_DAY - 1, 'fallbackMinuteOfDay');
        $this->preset = $preset;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSchedule(): Schedule
    {
        return $this->schedule;
    }

    public function getWeekday(): int
    {
        return $this->weekday;
    }

    public function getStartType(): SlotStartType
    {
        return $this->startType;
    }

    public function getMinuteOfDay(): int
    {
        return $this->minuteOfDay;
    }

    public function getAnchorEntityId(): ?string
    {
        return $this->anchorEntityId;
    }

    public function getOffsetMinutes(): int
    {
        return $this->offsetMinutes;
    }

    public function getFallbackMinuteOfDay(): int
    {
        return $this->fallbackMinuteOfDay;
    }

    public function getPreset(): Preset
    {
        return $this->preset;
    }

    public function changePreset(Preset $preset): void
    {
        $this->preset = $preset;
    }
}
