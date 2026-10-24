<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use App\Domain\Climate\HvacMode;
use App\Infrastructure\Persistence\Repository\HeatingRateRepository;
use App\Infrastructure\Persistence\Support\Guard;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Learned temperature slope (°C per hour) of a room for a mode.
 */
#[ORM\Entity(repositoryClass: HeatingRateRepository::class)]
#[ORM\Table(name: 'heating_rate')]
#[ORM\UniqueConstraint(name: 'uniq_heating_rate_room_mode', columns: ['room_id', 'mode'])]
final class HeatingRate
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Room $room;

    #[ORM\Column(type: 'string', length: 8, enumType: HvacMode::class)]
    private HvacMode $mode;

    #[ORM\Column(type: 'float')]
    private float $degreesPerHour;

    #[ORM\Column(type: 'integer')]
    private int $samples;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Room $room, HvacMode $mode, float $degreesPerHour, \DateTimeImmutable $updatedAt, int $samples = 1)
    {
        $this->id = Uuid::v7();
        $this->room = $room;
        $this->mode = Guard::activeMode($mode, 'mode');
        $this->degreesPerHour = self::positiveRate($degreesPerHour);
        $this->samples = Guard::positiveInt($samples, 'samples');
        $this->updatedAt = $updatedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRoom(): Room
    {
        return $this->room;
    }

    public function getMode(): HvacMode
    {
        return $this->mode;
    }

    public function getDegreesPerHour(): float
    {
        return $this->degreesPerHour;
    }

    public function getSamples(): int
    {
        return $this->samples;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Stores a new learned rate and counts one more sample.
     */
    public function learn(float $degreesPerHour, \DateTimeImmutable $at): void
    {
        $this->degreesPerHour = self::positiveRate($degreesPerHour);
        ++$this->samples;
        $this->updatedAt = $at;
    }

    private static function positiveRate(float $rate): float
    {
        if ($rate <= 0.0) {
            throw new \InvalidArgumentException('"degreesPerHour" must be strictly positive.');
        }

        return $rate;
    }
}
