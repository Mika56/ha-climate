<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

use App\Domain\Room\PresenceSignalKind;
use App\Infrastructure\Persistence\Entity\Room;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The whole configuration of a room (`RoomInput` of the contract). `heatingScheduleRef` and
 * `coolingScheduleRef` only mean something in a backup, where rooms point at the schedules of the same file;
 * so does the legacy `presenceSensor` of the backups made before the presence signals.
 */
final readonly class RoomRequest
{
    /**
     * @param list<string>                   $openings
     * @param list<DeviceRequest>            $devices
     * @param list<PresetTemperatureRequest> $presetTemperatures
     * @param list<PresenceSignalRequest>    $presenceSignals
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 64)]
        public string $name,
        #[Assert\Length(max: 255)]
        public ?string $areaId = null,
        #[Assert\Regex(pattern: EntityIdPattern::ENTITY_ID)]
        public ?string $temperatureSensor = null,
        #[Assert\Count(max: Room::MAX_PRESENCE_SIGNALS)]
        #[Assert\Valid]
        public array $presenceSignals = [],
        #[Assert\All([new Assert\Regex(pattern: EntityIdPattern::ENTITY_ID)])]
        public array $openings = [],
        #[Assert\Range(min: 0, max: 86400)]
        public int $vacancyDelaySeconds = 1200,
        #[Assert\Range(min: 0, max: 3600)]
        public int $windowCutDelaySeconds = 120,
        #[Assert\Range(min: 0, max: 86400)]
        public int $windowNotifyDelaySeconds = 1800,
        public bool $preheatEnabled = true,
        #[Assert\Range(min: 0, max: 720)]
        public int $preheatMaxMinutes = 120,
        #[Assert\Uuid]
        public ?string $heatingScheduleId = null,
        #[Assert\Uuid]
        public ?string $coolingScheduleId = null,
        #[Assert\Valid]
        public array $devices = [],
        #[Assert\Valid]
        public array $presetTemperatures = [],
        public ?string $heatingScheduleRef = null,
        public ?string $coolingScheduleRef = null,
        #[Assert\Regex(pattern: EntityIdPattern::ENTITY_ID)]
        public ?string $presenceSensor = null,
    ) {
    }

    /**
     * The presence signals of the room. A request that still carries the single `presenceSensor` of the
     * backups made before the presence signals existed (and no signal) gets it as one `active` signal.
     *
     * @return list<PresenceSignalRequest>
     */
    public function signals(): array
    {
        if ([] === $this->presenceSignals && null !== $this->presenceSensor) {
            return [new PresenceSignalRequest($this->presenceSensor, PresenceSignalKind::Active)];
        }

        return $this->presenceSignals;
    }
}
