<?php

declare(strict_types=1);

namespace App\Application\Api\Backup;

use App\Application\Api\ConfigurationAudit;
use App\Application\Api\EngineNotifier;
use App\Application\Api\Exception\Violations;
use App\Application\Api\IngressUser;
use App\Application\Api\Input\BackupRequest;
use App\Application\Api\Input\RoomRequest;
use App\Application\Api\Input\ScheduleRequest;
use App\Application\Api\Preset\PresetService;
use App\Application\Api\Room\RoomApplier;
use App\Application\Api\Schedule\ScheduleBuilder;
use App\Application\Api\Settings\NotificationSettingsApplier;
use App\Application\Api\Settings\SettingsApplier;
use App\Domain\Climate\HvacMode;
use App\Infrastructure\Persistence\Entity\Preset;
use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Entity\Schedule;
use App\Infrastructure\Persistence\Repository\GlobalSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Replaces the whole configuration (settings, presets, schedules, rooms and their devices, notifications) with
 * the content of a backup, in one transaction. The document is validated entirely first: nothing changes
 * unless it is fully consistent. What the engine produced (audit trail, timers, command log, learned rates,
 * alerts) is kept, except for what belongs to a room: the overrides, rates and alerts of the previous rooms go
 * with them. The restore is audited and the engine told to reload.
 */
final readonly class BackupRestorer
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GlobalSettingsRepository $settings,
        private SettingsApplier $settingsApplier,
        private NotificationSettingsApplier $notificationsApplier,
        private ScheduleBuilder $scheduleBuilder,
        private RoomApplier $roomApplier,
        private ConfigurationAudit $audit,
        private EngineNotifier $engine,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array{rooms: int, schedules: int, presets: int}
     *
     * @throws \App\Application\Api\Exception\ValidationException
     */
    public function restore(BackupRequest $backup, ?IngressUser $user): array
    {
        $this->validate($backup);

        $this->entityManager->wrapInTransaction(function () use ($backup, $user): void {
            // Rooms first: their devices, preset temperatures, overrides, rates and alerts follow by cascade,
            // and the audit entries keep their text (the room link is cleared, the name stays).
            foreach ([Room::class, Schedule::class, Preset::class] as $entity) {
                $this->entityManager->createQuery(\sprintf('DELETE FROM %s e', $entity))->execute();
            }
            $this->entityManager->clear();

            $presets = [];
            foreach ($backup->presets as $request) {
                $preset = new Preset($request->key, trim($request->label), $request->color, $request->position);
                $this->entityManager->persist($preset);
                $presets[$request->key] = $preset;
            }

            $schedules = [];
            foreach ($backup->schedules as $request) {
                $schedule = new Schedule(trim($request->name), $request->mode);
                $this->scheduleBuilder->fill($schedule, $request, $presets);
                $this->entityManager->persist($schedule);
                if (null !== $request->ref) {
                    $schedules[$request->ref] = $schedule;
                }
            }

            foreach ($backup->rooms as $position => $request) {
                $room = new Room(trim($request->name), $position);
                $this->roomApplier->apply(
                    $room,
                    $request,
                    null === $request->heatingScheduleRef ? null : $schedules[$request->heatingScheduleRef],
                    null === $request->coolingScheduleRef ? null : $schedules[$request->coolingScheduleRef],
                    $presets,
                );
                $this->entityManager->persist($room);
            }

            $settings = $this->settings->get();
            $wasPaused = $settings->isPaused();
            $this->settingsApplier->apply($settings, $backup->settings->toSettingsRequest());
            $this->notificationsApplier->apply($backup->notifications);

            $this->audit->record($user, 'restored', $this->counts($backup), null, $this->counts($backup));
            if ($wasPaused !== $settings->isPaused()) {
                $this->audit->recordPause($user, $settings->isPaused());
            }
            $this->entityManager->flush();
        });

        $this->engine->configurationChanged();

        return $this->counts($backup);
    }

    /**
     * @return array{rooms: int, schedules: int, presets: int}
     */
    private function counts(BackupRequest $backup): array
    {
        return ['rooms' => \count($backup->rooms), 'schedules' => \count($backup->schedules), 'presets' => \count($backup->presets)];
    }

    private function validate(BackupRequest $backup): void
    {
        $violations = new Violations($this->translator);

        $presets = [];
        foreach ($backup->presets as $index => $preset) {
            if (isset($presets[$preset->key])) {
                $violations->add(\sprintf('presets[%d].key', $index), 'api.duplicate_value');
            }
            $presets[$preset->key] = true;
        }
        foreach (PresetService::SYSTEM_KEYS as $required) {
            if (!isset($presets[$required])) {
                $violations->add('presets', 'api.required_preset', ['preset' => $required]);
            }
        }

        $modes = [];
        foreach ($backup->schedules as $index => $schedule) {
            $this->validateSchedule($schedule, $index, $presets, $modes, $violations);
        }

        $devices = [];
        foreach ($backup->rooms as $index => $room) {
            $this->validateRoom($room, $index, $presets, $modes, $devices, $violations);
        }

        $violations->throwIfAny();
    }

    /**
     * @param array<string, true>   $presets
     * @param array<string, string> $modes   the mode of each schedule by ref, filled here
     */
    private function validateSchedule(ScheduleRequest $schedule, int $index, array $presets, array &$modes, Violations $violations): void
    {
        if (null === $schedule->ref || '' === $schedule->ref) {
            $violations->add(\sprintf('schedules[%d].ref', $index), 'This value should not be blank.');
        } elseif (isset($modes[$schedule->ref])) {
            $violations->add(\sprintf('schedules[%d].ref', $index), 'api.duplicate_value');
        } else {
            $modes[$schedule->ref] = $schedule->mode->value;
        }

        foreach ($schedule->slots as $slotIndex => $slot) {
            if (!isset($presets[$slot->preset])) {
                $violations->add(\sprintf('schedules[%d].slots[%d].preset', $index, $slotIndex), 'api.unknown_preset', ['preset' => $slot->preset]);
            }
        }
    }

    /**
     * @param array<string, true>   $presets
     * @param array<string, string> $modes
     * @param array<string, true>   $devices the device entities already given to a room of the file, filled here
     */
    private function validateRoom(RoomRequest $room, int $index, array $presets, array $modes, array &$devices, Violations $violations): void
    {
        foreach ([['heatingScheduleRef', $room->heatingScheduleRef, HvacMode::Heat], ['coolingScheduleRef', $room->coolingScheduleRef, HvacMode::Cool]] as [$field, $ref, $mode]) {
            if (null === $ref) {
                continue;
            }
            $path = \sprintf('rooms[%d].%s', $index, $field);
            if (!isset($modes[$ref])) {
                $violations->add($path, 'api.unknown_schedule_ref', ['ref' => $ref]);
            } elseif ($modes[$ref] !== $mode->value) {
                $violations->add($path, 'api.schedule_mode', ['mode' => $mode->value]);
            }
        }

        $given = [];
        foreach ($room->presetTemperatures as $temperatureIndex => $temperature) {
            $path = \sprintf('rooms[%d].presetTemperatures[%d].preset', $index, $temperatureIndex);
            if (!isset($presets[$temperature->preset])) {
                $violations->add($path, 'api.unknown_preset', ['preset' => $temperature->preset]);
            } elseif (isset($given[$temperature->preset])) {
                $violations->add($path, 'api.duplicate_value');
            }
            $given[$temperature->preset] = true;
        }

        foreach ($room->devices as $deviceIndex => $device) {
            if (isset($devices[$device->entityId])) {
                $violations->add(\sprintf('rooms[%d].devices[%d].entityId', $index, $deviceIndex), 'api.duplicate_value');
            }
            $devices[$device->entityId] = true;
        }
    }
}
