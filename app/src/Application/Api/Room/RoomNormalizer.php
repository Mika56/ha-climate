<?php

declare(strict_types=1);

namespace App\Application\Api\Room;

use App\Domain\Climate\HvacMode;
use App\Infrastructure\Persistence\Entity\Device;
use App\Infrastructure\Persistence\Entity\HeatingRate;
use App\Infrastructure\Persistence\Entity\Preset;
use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Repository\HeatingRateRepository;

/**
 * A room as the API shows it: `RoomInput` fields plus its id, position and the learned heating/cooling rates.
 */
final readonly class RoomNormalizer
{
    public function __construct(private HeatingRateRepository $rates)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(Room $room): array
    {
        return [
            'id' => $room->getId()->toRfc4122(),
            'position' => $room->getPosition(),
            ...$this->configuration($room),
            'learnedRates' => [
                'heat' => $this->learnedRate($room, HvacMode::Heat),
                'cool' => $this->learnedRate($room, HvacMode::Cool),
            ],
        ];
    }

    /**
     * The part of the room an update replaces (no identity, no runtime data): what the audit trail compares.
     *
     * @return array<string, mixed>
     */
    public function configuration(Room $room): array
    {
        return [
            'name' => $room->getName(),
            'areaId' => $room->getHaAreaId(),
            'temperatureSensor' => $room->getTemperatureSensor(),
            'presenceSensor' => $room->getPresenceSensor(),
            'openings' => $room->getOpenings(),
            'vacancyDelaySeconds' => $room->getVacancyDelaySeconds(),
            'windowCutDelaySeconds' => $room->getWindowCutDelaySeconds(),
            'windowNotifyDelaySeconds' => $room->getWindowNotifyDelaySeconds(),
            'preheatEnabled' => $room->isPreheatEnabled(),
            'preheatMaxMinutes' => $room->getPreheatMaxMinutes(),
            'heatingScheduleId' => $room->getHeatingSchedule()?->getId()->toRfc4122(),
            'coolingScheduleId' => $room->getCoolingSchedule()?->getId()->toRfc4122(),
            'devices' => $this->devices($room),
            'presetTemperatures' => $this->presetTemperatures($room),
        ];
    }

    /**
     * @return list<array{entityId: string, role: string, minOnSeconds: int, minOffSeconds: int, hysteresis: float}>
     */
    private function devices(Room $room): array
    {
        $devices = $room->getDevices()->toArray();
        usort($devices, static fn (Device $a, Device $b): int => $a->getId()->toRfc4122() <=> $b->getId()->toRfc4122());

        return array_map(static fn (Device $device): array => [
            'entityId' => $device->getEntityId(),
            'role' => $device->getRole()->value,
            'minOnSeconds' => $device->getMinOnSeconds(),
            'minOffSeconds' => $device->getMinOffSeconds(),
            'hysteresis' => $device->getHysteresis(),
        ], $devices);
    }

    /**
     * One entry per preset that has a temperature, in the order of the presets.
     *
     * @return list<array{preset: string, heat: float|null, cool: float|null}>
     */
    private function presetTemperatures(Room $room): array
    {
        /** @var array<string, array{preset: Preset, heat: float|null, cool: float|null}> $byPreset */
        $byPreset = [];
        foreach ($room->getPresetTemperatures() as $entry) {
            $preset = $entry->getPreset();
            $byPreset[$preset->getKey()] ??= ['preset' => $preset, 'heat' => null, 'cool' => null];
            $byPreset[$preset->getKey()][HvacMode::Heat === $entry->getMode() ? 'heat' : 'cool'] = $entry->getTemperature();
        }

        uasort($byPreset, static fn (array $a, array $b): int => [$a['preset']->getPosition(), $a['preset']->getKey()] <=> [$b['preset']->getPosition(), $b['preset']->getKey()]);

        return array_values(array_map(static fn (array $entry): array => [
            'preset' => $entry['preset']->getKey(),
            'heat' => $entry['heat'],
            'cool' => $entry['cool'],
        ], $byPreset));
    }

    /**
     * @return array{degreesPerHour: float, samples: int}|null
     */
    private function learnedRate(Room $room, HvacMode $mode): ?array
    {
        $rate = $this->rates->findOneByRoomAndMode($room, $mode);

        return $rate instanceof HeatingRate ? ['degreesPerHour' => $rate->getDegreesPerHour(), 'samples' => $rate->getSamples()] : null;
    }
}
