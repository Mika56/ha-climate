<?php

declare(strict_types=1);

namespace App\Application\Api\Schedule;

use App\Application\Api\ConfigurationAudit;
use App\Application\Api\EngineNotifier;
use App\Application\Api\Exception\NotFoundException;
use App\Application\Api\Exception\Violations;
use App\Application\Api\IngressUser;
use App\Application\Api\Input\DuplicateScheduleRequest;
use App\Application\Api\Input\ScheduleRequest;
use App\Domain\Climate\HvacMode;
use App\Infrastructure\Persistence\Entity\Preset;
use App\Infrastructure\Persistence\Entity\Schedule;
use App\Infrastructure\Persistence\Repository\PresetRepository;
use App\Infrastructure\Persistence\Repository\RoomRepository;
use App\Infrastructure\Persistence\Repository\ScheduleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Schedules: weekly lists of slots, each starting at a fixed time or at an `input_datetime`, shared by rooms.
 */
final readonly class ScheduleService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ScheduleRepository $schedules,
        private RoomRepository $rooms,
        private PresetRepository $presets,
        private ScheduleNormalizer $normalizer,
        private ScheduleBuilder $builder,
        private ConfigurationAudit $audit,
        private EngineNotifier $engine,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return array_map(fn (Schedule $schedule): array => $this->normalizer->normalize($schedule, $this->usedBy($schedule)), $this->schedules->findAllOrdered());
    }

    /**
     * @return array<string, mixed>
     *
     * @throws NotFoundException
     */
    public function get(string $id): array
    {
        $schedule = $this->find($id);

        return $this->normalizer->normalize($schedule, $this->usedBy($schedule));
    }

    /**
     * @return array<string, mixed>
     */
    public function create(ScheduleRequest $request, ?IngressUser $user): array
    {
        $presets = $this->checkedPresets($request);
        $schedule = new Schedule(trim($request->name), $request->mode);
        $this->builder->fill($schedule, $request, $presets);

        $this->entityManager->persist($schedule);
        $this->audit->record($user, 'schedule_created', ['schedule' => $schedule->getName()], null, $this->normalizer->input($schedule));
        $this->entityManager->flush();
        $this->engine->configurationChanged();

        return $this->normalizer->normalize($schedule, []);
    }

    /**
     * Replaces name and slots. The mode of a schedule used by rooms cannot change (a heating schedule would
     * end up driving cooling): it is simply kept, as the contract only lets an update replace name and slots.
     *
     * @return array<string, mixed>
     *
     * @throws NotFoundException
     */
    public function update(string $id, ScheduleRequest $request, ?IngressUser $user): array
    {
        $schedule = $this->find($id);
        $presets = $this->checkedPresets($request);
        $before = $this->normalizer->input($schedule);

        $schedule->rename(trim($request->name));
        $this->builder->fill($schedule, $request, $presets);
        $this->audit->record($user, 'schedule_updated', ['schedule' => $schedule->getName()], $before, $this->normalizer->input($schedule));
        $this->entityManager->flush();
        $this->engine->configurationChanged();

        return $this->normalizer->normalize($schedule, $this->usedBy($schedule));
    }

    /**
     * @throws NotFoundException
     */
    public function delete(string $id, ?IngressUser $user): void
    {
        $schedule = $this->find($id);
        $usedBy = $this->usedBy($schedule);

        // Rooms lose it explicitly: the database would clear the link too, but not the entities already loaded.
        foreach ($this->rooms->findAllOrdered() as $room) {
            if ($room->getHeatingSchedule() === $schedule) {
                $room->assignSchedule(HvacMode::Heat, null);
            }
            if ($room->getCoolingSchedule() === $schedule) {
                $room->assignSchedule(HvacMode::Cool, null);
            }
        }

        $this->audit->record($user, 'schedule_deleted', ['schedule' => $schedule->getName()], $this->normalizer->input($schedule) + ['usedBy' => $usedBy], null);
        $this->entityManager->flush();
        $this->entityManager->remove($schedule);
        $this->entityManager->flush();
        $this->engine->configurationChanged();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws NotFoundException
     */
    public function duplicate(string $id, DuplicateScheduleRequest $request, ?IngressUser $user): array
    {
        $source = $this->find($id);
        $copy = new Schedule(trim($request->name), $source->getMode());
        foreach ($source->getSlots() as $slot) {
            $copy->addSlot(
                $slot->getWeekday(),
                $slot->getStartType(),
                $slot->getMinuteOfDay(),
                $slot->getPreset(),
                $slot->getAnchorEntityId(),
                $slot->getOffsetMinutes(),
                $slot->getFallbackMinuteOfDay(),
            );
        }

        $this->entityManager->persist($copy);
        $this->audit->record($user, 'schedule_duplicated', ['source' => $source->getName(), 'schedule' => $copy->getName()], null, $this->normalizer->input($copy));
        $this->entityManager->flush();
        $this->engine->configurationChanged();

        return $this->normalizer->normalize($copy, []);
    }

    private function find(string $id): Schedule
    {
        $schedule = Uuid::isValid($id) ? $this->schedules->find(Uuid::fromString($id)) : null;

        return $schedule ?? throw new NotFoundException('Schedule', $id);
    }

    /**
     * @return array<string, Preset>
     */
    private function checkedPresets(ScheduleRequest $request): array
    {
        $presets = [];
        foreach ($this->presets->findAllOrdered() as $preset) {
            $presets[$preset->getKey()] = $preset;
        }

        $violations = new Violations($this->translator);
        $this->builder->checkPresets($request, $presets, $violations);
        $violations->throwIfAny();

        return $presets;
    }

    /**
     * @return list<array{roomId: string, roomName: string}>
     */
    private function usedBy(Schedule $schedule): array
    {
        $users = [];
        foreach ($this->rooms->findAllOrdered() as $room) {
            if ($room->getHeatingSchedule() === $schedule || $room->getCoolingSchedule() === $schedule) {
                $users[] = ['roomId' => $room->getId()->toRfc4122(), 'roomName' => $room->getName()];
            }
        }

        return $users;
    }
}
