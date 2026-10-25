<?php

declare(strict_types=1);

namespace App\Application\Api\Room;

use App\Application\Api\Input\RoomRequest;
use App\Domain\Climate\HvacMode;
use App\Infrastructure\Persistence\Entity\Preset;
use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Entity\Schedule;

/**
 * Makes a room entity match a validated {@see RoomRequest}: the request replaces the whole aggregate, so
 * devices and preset temperatures that are not in it are removed and the others are updated in place.
 */
final readonly class RoomApplier
{
    /**
     * @param array<string, Preset> $presets every preset by key; the request only refers to existing ones
     */
    public function apply(Room $room, RoomRequest $request, ?Schedule $heatingSchedule, ?Schedule $coolingSchedule, array $presets): void
    {
        $room->rename(trim($request->name));
        $room->linkToArea($request->areaId);
        $room->configureSensors($request->temperatureSensor, $request->presenceSensor, $request->openings);
        $room->configureDelays($request->vacancyDelaySeconds, $request->windowCutDelaySeconds, $request->windowNotifyDelaySeconds);
        $room->configurePreheat($request->preheatEnabled, $request->preheatMaxMinutes);
        $room->assignSchedule(HvacMode::Heat, $heatingSchedule);
        $room->assignSchedule(HvacMode::Cool, $coolingSchedule);

        $this->syncDevices($room, $request);
        $this->syncPresetTemperatures($room, $request, $presets);
    }

    private function syncDevices(Room $room, RoomRequest $request): void
    {
        $wanted = [];
        foreach ($request->devices as $device) {
            $wanted[$device->entityId] = $device;
        }

        foreach ($room->getDevices()->toArray() as $existing) {
            $device = $wanted[$existing->getEntityId()] ?? null;
            if (null === $device) {
                $room->removeDevice($existing);

                continue;
            }

            $existing->changeRole($device->role);
            $existing->configureAntiCycling($device->minOnSeconds, $device->minOffSeconds, $device->hysteresis);
            unset($wanted[$existing->getEntityId()]);
        }

        foreach ($wanted as $device) {
            $room->addDevice($device->entityId, $device->role, $device->minOnSeconds, $device->minOffSeconds, $device->hysteresis);
        }
    }

    /**
     * @param array<string, Preset> $presets
     */
    private function syncPresetTemperatures(Room $room, RoomRequest $request, array $presets): void
    {
        /** @var array<string, float> $wanted indexed by "<preset key>/<mode>" */
        $wanted = [];
        foreach ($request->presetTemperatures as $entry) {
            if (null !== $entry->heat) {
                $wanted[$entry->preset.'/'.HvacMode::Heat->value] = $entry->heat;
            }
            if (null !== $entry->cool) {
                $wanted[$entry->preset.'/'.HvacMode::Cool->value] = $entry->cool;
            }
        }

        foreach ($room->getPresetTemperatures()->toArray() as $existing) {
            $key = $existing->getPreset()->getKey().'/'.$existing->getMode()->value;
            if (!isset($wanted[$key])) {
                $room->removePresetTemperature($existing);

                continue;
            }

            $existing->changeTemperature($wanted[$key]);
            unset($wanted[$key]);
        }

        foreach ($wanted as $key => $temperature) {
            [$presetKey, $mode] = explode('/', $key, 2);
            $room->setPresetTemperature($presets[$presetKey], HvacMode::from($mode), $temperature);
        }
    }
}
