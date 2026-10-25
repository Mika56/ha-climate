<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The whole configuration of a room (`RoomInput` of the contract). `heatingScheduleRef` and
 * `coolingScheduleRef` only mean something in a backup, where rooms point at the schedules of the same file.
 */
final readonly class RoomRequest
{
    /**
     * @param list<string>                   $openings
     * @param list<DeviceRequest>            $devices
     * @param list<PresetTemperatureRequest> $presetTemperatures
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 64)]
        public string $name,
        #[Assert\Length(max: 255)]
        public ?string $areaId = null,
        #[Assert\Regex(pattern: EntityIdPattern::ENTITY_ID)]
        public ?string $temperatureSensor = null,
        #[Assert\Regex(pattern: EntityIdPattern::ENTITY_ID)]
        public ?string $presenceSensor = null,
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
    ) {
    }
}
