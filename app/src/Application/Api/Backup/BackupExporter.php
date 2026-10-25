<?php

declare(strict_types=1);

namespace App\Application\Api\Backup;

use App\Application\Api\ApiTime;
use App\Application\Api\Input\BackupRequest;
use App\Application\Api\Preset\PresetService;
use App\Application\Api\Room\RoomNormalizer;
use App\Application\Api\Schedule\ScheduleNormalizer;
use App\Application\Api\Settings\NotificationSettingsService;
use App\Application\Api\Settings\SettingsNormalizer;
use App\Infrastructure\Persistence\Repository\GlobalSettingsRepository;
use App\Infrastructure\Persistence\Repository\RoomRepository;
use App\Infrastructure\Persistence\Repository\ScheduleRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * The whole configuration as one versioned document (`Backup` of the contract). Rooms point at schedules by a
 * local `ref` (`schedule-1`, `schedule-2`...) so the file does not depend on database ids: exporting, restoring
 * and exporting again gives the same document, apart from its date.
 */
final readonly class BackupExporter
{
    public function __construct(
        private GlobalSettingsRepository $settings,
        private SettingsNormalizer $settingsNormalizer,
        private PresetService $presets,
        private ScheduleRepository $schedules,
        private ScheduleNormalizer $scheduleNormalizer,
        private RoomRepository $rooms,
        private RoomNormalizer $roomNormalizer,
        private NotificationSettingsService $notifications,
        private ApiTime $time,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $refs = [];
        $schedules = [];
        $ordered = $this->schedules->findAll();
        usort($ordered, static fn ($a, $b): int => [$a->getName(), $a->getMode()->value, $a->getId()->toRfc4122()] <=> [$b->getName(), $b->getMode()->value, $b->getId()->toRfc4122()]);
        foreach ($ordered as $index => $schedule) {
            $ref = \sprintf('schedule-%d', $index + 1);
            $refs[$schedule->getId()->toRfc4122()] = $ref;
            $schedules[] = ['ref' => $ref, ...$this->scheduleNormalizer->input($schedule)];
        }

        $rooms = [];
        foreach ($this->rooms->findAllOrdered() as $room) {
            $configuration = $this->roomNormalizer->configuration($room);
            $heating = $configuration['heatingScheduleId'];
            $cooling = $configuration['coolingScheduleId'];
            unset($configuration['heatingScheduleId'], $configuration['coolingScheduleId']);
            $rooms[] = $configuration + [
                'heatingScheduleRef' => \is_string($heating) ? ($refs[$heating] ?? null) : null,
                'coolingScheduleRef' => \is_string($cooling) ? ($refs[$cooling] ?? null) : null,
            ];
        }

        return [
            'format' => BackupRequest::FORMAT,
            'version' => BackupRequest::VERSION,
            'exportedAt' => $this->time->format($this->clock->now()),
            'settings' => $this->settingsNormalizer->normalize($this->settings->get(), false),
            'presets' => $this->presets->list(),
            'schedules' => $schedules,
            'rooms' => $rooms,
            'notifications' => $this->notifications->stored(),
        ];
    }
}
