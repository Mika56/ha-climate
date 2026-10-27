<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use App\Domain\Climate\HvacMode;
use App\Domain\Device\DeviceRole;
use App\Domain\HomeAssistant\EntityId;
use App\Domain\Room\PresenceSignal;
use App\Domain\Room\PresenceSignalKind;
use App\Infrastructure\Persistence\Repository\RoomRepository;
use App\Infrastructure\Persistence\Support\Guard;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: RoomRepository::class)]
#[ORM\Table(name: 'room')]
final class Room
{
    public const int MAX_PRESENCE_SIGNALS = 20;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: 'string', length: 128)]
    private string $name;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $haAreaId = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $temperatureSensor = null;

    /**
     * @var list<array{entityId: string, kind: string, threshold: float|null, value: string|null}>
     */
    #[ORM\Column(type: 'json')]
    private array $presenceSignals = [];

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $openings = [];

    #[ORM\Column(type: 'integer')]
    private int $vacancyDelaySeconds = 1800;

    #[ORM\Column(type: 'integer')]
    private int $windowCutDelaySeconds = 120;

    #[ORM\Column(type: 'integer')]
    private int $windowNotifyDelaySeconds = 1800;

    #[ORM\Column(type: 'boolean')]
    private bool $preheatEnabled = false;

    #[ORM\Column(type: 'integer')]
    private int $preheatMaxMinutes = 120;

    #[ORM\ManyToOne(targetEntity: Schedule::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Schedule $heatingSchedule = null;

    #[ORM\ManyToOne(targetEntity: Schedule::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Schedule $coolingSchedule = null;

    #[ORM\Column(type: 'integer')]
    private int $position;

    /**
     * @var Collection<int, Device>
     */
    #[ORM\OneToMany(targetEntity: Device::class, mappedBy: 'room', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $devices;

    /**
     * @var Collection<int, RoomPresetTemperature>
     */
    #[ORM\OneToMany(targetEntity: RoomPresetTemperature::class, mappedBy: 'room', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $presetTemperatures;

    public function __construct(string $name, int $position = 0)
    {
        $this->id = Uuid::v7();
        $this->name = Guard::notBlank($name, 'name');
        $this->position = Guard::nonNegativeInt($position, 'position');
        $this->devices = new ArrayCollection();
        $this->presetTemperatures = new ArrayCollection();
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

    public function getHaAreaId(): ?string
    {
        return $this->haAreaId;
    }

    public function linkToArea(?string $haAreaId): void
    {
        $this->haAreaId = Guard::nullableNotBlank($haAreaId, 'haAreaId');
    }

    public function getTemperatureSensor(): ?string
    {
        return $this->temperatureSensor;
    }

    /**
     * @return list<PresenceSignal>
     */
    public function getPresenceSignals(): array
    {
        return array_map(
            static fn (array $signal): PresenceSignal => new PresenceSignal(new EntityId($signal['entityId']), PresenceSignalKind::from($signal['kind']), $signal['threshold'], $signal['value']),
            $this->presenceSignals,
        );
    }

    /**
     * @return list<string>
     */
    public function getOpenings(): array
    {
        return $this->openings;
    }

    /**
     * @param list<PresenceSignal> $presenceSignals
     * @param list<string>         $openings
     */
    public function configureSensors(?string $temperatureSensor, array $presenceSignals, array $openings): void
    {
        if (\count($presenceSignals) > self::MAX_PRESENCE_SIGNALS) {
            throw new \InvalidArgumentException(sprintf('A room has at most %d presence signals.', self::MAX_PRESENCE_SIGNALS));
        }

        $stored = [];
        foreach ($presenceSignals as $signal) {
            $entityId = (string) $signal->entityId;
            if (isset($stored[$entityId])) {
                throw new \InvalidArgumentException(sprintf('"%s" is already a presence signal of the room.', $entityId));
            }
            $stored[$entityId] = ['entityId' => $entityId, 'kind' => $signal->kind->value, 'threshold' => $signal->threshold, 'value' => $signal->value];
        }

        $this->temperatureSensor = Guard::nullableNotBlank($temperatureSensor, 'temperatureSensor');
        $this->presenceSignals = array_values($stored);
        $this->openings = Guard::stringList($openings, 'opening');
    }

    public function getVacancyDelaySeconds(): int
    {
        return $this->vacancyDelaySeconds;
    }

    public function getWindowCutDelaySeconds(): int
    {
        return $this->windowCutDelaySeconds;
    }

    public function getWindowNotifyDelaySeconds(): int
    {
        return $this->windowNotifyDelaySeconds;
    }

    public function configureDelays(int $vacancyDelaySeconds, int $windowCutDelaySeconds, int $windowNotifyDelaySeconds): void
    {
        $this->vacancyDelaySeconds = Guard::nonNegativeInt($vacancyDelaySeconds, 'vacancyDelaySeconds');
        $this->windowCutDelaySeconds = Guard::nonNegativeInt($windowCutDelaySeconds, 'windowCutDelaySeconds');
        $this->windowNotifyDelaySeconds = Guard::nonNegativeInt($windowNotifyDelaySeconds, 'windowNotifyDelaySeconds');
    }

    public function isPreheatEnabled(): bool
    {
        return $this->preheatEnabled;
    }

    public function getPreheatMaxMinutes(): int
    {
        return $this->preheatMaxMinutes;
    }

    public function configurePreheat(bool $enabled, int $maxMinutes): void
    {
        $this->preheatEnabled = $enabled;
        $this->preheatMaxMinutes = Guard::nonNegativeInt($maxMinutes, 'preheatMaxMinutes');
    }

    public function getHeatingSchedule(): ?Schedule
    {
        return $this->heatingSchedule;
    }

    public function getCoolingSchedule(): ?Schedule
    {
        return $this->coolingSchedule;
    }

    public function getScheduleFor(HvacMode $mode): ?Schedule
    {
        return match ($mode) {
            HvacMode::Heat => $this->heatingSchedule,
            HvacMode::Cool => $this->coolingSchedule,
            HvacMode::Off => null,
        };
    }

    public function assignSchedule(HvacMode $mode, ?Schedule $schedule): void
    {
        Guard::activeMode($mode, 'mode');
        if (null !== $schedule && $schedule->getMode() !== $mode) {
            throw new \InvalidArgumentException(sprintf('A %s schedule cannot be used as the %s schedule of a room.', $schedule->getMode()->value, $mode->value));
        }

        if (HvacMode::Heat === $mode) {
            $this->heatingSchedule = $schedule;
        } else {
            $this->coolingSchedule = $schedule;
        }
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function moveTo(int $position): void
    {
        $this->position = Guard::nonNegativeInt($position, 'position');
    }

    /**
     * @return Collection<int, Device>
     */
    public function getDevices(): Collection
    {
        return $this->devices;
    }

    public function addDevice(
        string $entityId,
        DeviceRole $role,
        int $minOnSeconds = 0,
        int $minOffSeconds = 0,
        float $hysteresis = 0.5,
    ): Device {
        $device = new Device($this, $entityId, $role, $minOnSeconds, $minOffSeconds, $hysteresis);
        $this->devices->add($device);

        return $device;
    }

    public function removeDevice(Device $device): void
    {
        $this->devices->removeElement($device);
    }

    /**
     * @return Collection<int, RoomPresetTemperature>
     */
    public function getPresetTemperatures(): Collection
    {
        return $this->presetTemperatures;
    }

    /**
     * Creates or updates the temperature of a (preset, mode) pair.
     */
    public function setPresetTemperature(Preset $preset, HvacMode $mode, float $temperature): RoomPresetTemperature
    {
        foreach ($this->presetTemperatures as $existing) {
            if ($existing->getPreset() === $preset && $existing->getMode() === $mode) {
                $existing->changeTemperature($temperature);

                return $existing;
            }
        }

        $created = new RoomPresetTemperature($this, $preset, $mode, $temperature);
        $this->presetTemperatures->add($created);

        return $created;
    }

    public function removePresetTemperature(RoomPresetTemperature $temperature): void
    {
        $this->presetTemperatures->removeElement($temperature);
    }
}
