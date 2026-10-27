<?php

declare(strict_types=1);

namespace App\Application\Api\Room;

use App\Application\Api\Exception\ValidationException;
use App\Application\Api\Exception\Violations;
use App\Application\Api\Input\RoomRequest;
use App\Domain\Climate\HvacMode;
use App\Infrastructure\Persistence\Entity\Preset;
use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Entity\Schedule;
use App\Infrastructure\Persistence\Repository\DeviceRepository;
use App\Infrastructure\Persistence\Repository\PresetRepository;
use App\Infrastructure\Persistence\Repository\ScheduleRepository;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Checks a room request against the data it points at: the schedules and presets must exist, schedules must
 * fit the mode they are used for, the presence signals must be consistent, and a device cannot be driven by two rooms.
 */
final readonly class RoomChecker
{
    public function __construct(
        private PresetRepository $presets,
        private ScheduleRepository $schedules,
        private DeviceRepository $devices,
        private PresenceSignalsChecker $presenceSignals,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param Room|null $room the room being updated (its own devices are not "taken")
     *
     * @return array{heating: Schedule|null, cooling: Schedule|null, presets: array<string, Preset>}
     *
     * @throws ValidationException
     */
    public function check(RoomRequest $request, ?Room $room): array
    {
        $violations = new Violations($this->translator);

        $heating = $this->schedule($request->heatingScheduleId, HvacMode::Heat, 'heatingScheduleId', $violations);
        $cooling = $this->schedule($request->coolingScheduleId, HvacMode::Cool, 'coolingScheduleId', $violations);

        $presets = [];
        foreach ($this->presets->findAllOrdered() as $preset) {
            $presets[$preset->getKey()] = $preset;
        }

        $seen = [];
        foreach ($request->presetTemperatures as $index => $entry) {
            if (!isset($presets[$entry->preset])) {
                $violations->add(\sprintf('presetTemperatures[%d].preset', $index), 'api.unknown_preset', ['preset' => $entry->preset]);
            } elseif (isset($seen[$entry->preset])) {
                $violations->add(\sprintf('presetTemperatures[%d].preset', $index), 'api.duplicate_value');
            }
            $seen[$entry->preset] = true;
        }

        $this->presenceSignals->check($request->signals(), 'presenceSignals', $violations);

        $given = [];
        foreach ($request->devices as $index => $device) {
            $path = \sprintf('devices[%d].entityId', $index);
            if (isset($given[$device->entityId])) {
                $violations->add($path, 'api.duplicate_value');
            }
            $given[$device->entityId] = true;

            $owner = $this->devices->findOneByEntityId($device->entityId)?->getRoom();
            if (null !== $owner && (null === $room || !$owner->getId()->equals($room->getId()))) {
                $violations->add($path, 'api.device_taken', ['room' => $owner->getName()]);
            }
        }

        $violations->throwIfAny();

        return ['heating' => $heating, 'cooling' => $cooling, 'presets' => $presets];
    }

    private function schedule(?string $id, HvacMode $mode, string $path, Violations $violations): ?Schedule
    {
        if (null === $id) {
            return null;
        }

        $schedule = Uuid::isValid($id) ? $this->schedules->find(Uuid::fromString($id)) : null;
        if (null === $schedule) {
            $violations->add($path, 'api.unknown_schedule');

            return null;
        }
        if ($schedule->getMode() !== $mode) {
            $violations->add($path, 'api.schedule_mode', ['mode' => $mode->value]);

            return null;
        }

        return $schedule;
    }
}
