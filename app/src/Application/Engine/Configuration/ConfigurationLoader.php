<?php

declare(strict_types=1);

namespace App\Application\Engine\Configuration;

use App\Domain\Climate\Temperature;
use App\Domain\Decision\GlobalPolicy;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\VacationPeriod;
use App\Domain\Device\AntiCyclingPolicy;
use App\Domain\Device\Device;
use App\Domain\Exception\DomainExceptionInterface;
use App\Domain\HomeAssistant\EntityId;
use App\Domain\Preset\PresetKey;
use App\Domain\Room\PresetTemperatures;
use App\Domain\Room\RoomId;
use App\Domain\Schedule\AnchoredSlotStart;
use App\Domain\Schedule\FixedSlotStart;
use App\Domain\Schedule\ScheduleId;
use App\Domain\Schedule\ScheduleSlot;
use App\Domain\Schedule\SlotStart;
use App\Domain\Schedule\Weekday;
use App\Domain\Schedule\WeeklySchedule;
use App\Infrastructure\Persistence\Entity\Device as DeviceEntity;
use App\Infrastructure\Persistence\Entity\GlobalSettings;
use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Entity\Schedule;
use App\Infrastructure\Persistence\Entity\ScheduleSlot as ScheduleSlotEntity;
use App\Infrastructure\Persistence\Enum\SlotStartType;
use App\Infrastructure\Persistence\Repository\GlobalSettingsRepository;
use App\Infrastructure\Persistence\Repository\RoomRepository;
use App\Infrastructure\Persistence\Repository\ScheduleRepository;
use Psr\Log\LoggerInterface;

/**
 * Maps the Doctrine entities onto the domain model.
 *
 * A room or a schedule that violates a domain invariant (temperature out of range, empty schedule...) is
 * left out and reported in the log, so one bad record never prevents the other rooms from being driven.
 */
final readonly class ConfigurationLoader
{
    private const string VACANCY_PRESET = 'eco';
    private const string ABSENT_PRESET = 'away';

    public function __construct(
        private RoomRepository $rooms,
        private ScheduleRepository $schedules,
        private GlobalSettingsRepository $settings,
        private LoggerInterface $logger,
    ) {
    }

    public function load(): EngineConfiguration
    {
        $settings = $this->settings->get();

        return new EngineConfiguration(
            $this->loadRooms(),
            $this->loadSchedules(),
            new GlobalPolicy(
                $settings->getHouseEmptyDelaySeconds(),
                new PresetKey(self::VACANCY_PRESET),
                new PresetKey(self::ABSENT_PRESET),
            ),
            $settings->getSeason(),
            $this->lenientEntityIds($settings->getTrackedPersons()),
            $this->vacation($settings),
            $this->lenientEntityIds(null === $settings->getWeatherEntity() ? [] : [$settings->getWeatherEntity()])[0] ?? null,
            $settings->getAuditRetentionDays(),
            $settings->getManualOverrideMinutes(),
        );
    }

    /**
     * @return list<RoomConfiguration>
     */
    private function loadRooms(): array
    {
        $rooms = [];
        foreach ($this->rooms->findAllOrdered() as $room) {
            try {
                $rooms[] = $this->mapRoom($room);
            } catch (DomainExceptionInterface $e) {
                $this->logger->error('Room "{room}" ignored, its configuration is invalid: {message}', ['room' => $room->getName(), 'message' => $e->getMessage()]);
            }
        }

        return $rooms;
    }

    /**
     * @return array<string, WeeklySchedule>
     */
    private function loadSchedules(): array
    {
        $schedules = [];
        foreach ($this->schedules->findAllOrdered() as $schedule) {
            try {
                $mapped = $this->mapSchedule($schedule);
                $schedules[(string) $mapped->id] = $mapped;
            } catch (DomainExceptionInterface $e) {
                $this->logger->error('Schedule "{schedule}" ignored, its configuration is invalid: {message}', ['schedule' => $schedule->getName(), 'message' => $e->getMessage()]);
            }
        }

        return $schedules;
    }

    private function mapRoom(Room $room): RoomConfiguration
    {
        $devices = [];
        foreach ($room->getDevices() as $device) {
            $devices[] = $this->mapDevice($device);
        }

        $temperatures = PresetTemperatures::empty();
        foreach ($room->getPresetTemperatures() as $entry) {
            $temperatures = $temperatures->with(new PresetKey($entry->getPreset()->getKey()), $entry->getMode(), new Temperature($entry->getTemperature()));
        }

        $temperatureSensor = $room->getTemperatureSensor();
        $presence = $room->getPresenceSensor();

        return new RoomConfiguration(
            new RoomId($room->getId()->toRfc4122()),
            $room->getName(),
            $devices,
            $temperatures,
            $this->scheduleId($room->getHeatingSchedule()),
            $this->scheduleId($room->getCoolingSchedule()),
            null === $temperatureSensor ? null : new EntityId($temperatureSensor),
            null === $presence ? null : new EntityId($presence),
            $this->entityIds($room->getOpenings()),
            $room->getVacancyDelaySeconds(),
            $room->getWindowCutDelaySeconds(),
            $room->getWindowNotifyDelaySeconds(),
            $room->isPreheatEnabled(),
            $room->getPreheatMaxMinutes(),
        );
    }

    private function mapDevice(DeviceEntity $device): Device
    {
        return new Device(
            new EntityId($device->getEntityId()),
            $device->getRole(),
            new AntiCyclingPolicy($device->getMinOnSeconds(), $device->getMinOffSeconds(), $device->getHysteresis()),
        );
    }

    private function mapSchedule(Schedule $schedule): WeeklySchedule
    {
        $slots = [];
        foreach ($schedule->getSlots() as $slot) {
            $slots[] = new ScheduleSlot(Weekday::from($slot->getWeekday()), $this->slotStart($slot), new PresetKey($slot->getPreset()->getKey()));
        }

        return new WeeklySchedule(new ScheduleId($schedule->getId()->toRfc4122()), $schedule->getName(), $schedule->getMode(), $slots);
    }

    private function slotStart(ScheduleSlotEntity $slot): SlotStart
    {
        $anchor = $slot->getAnchorEntityId();
        if (SlotStartType::Anchored === $slot->getStartType() && null !== $anchor) {
            return new AnchoredSlotStart(new EntityId($anchor), $slot->getOffsetMinutes(), $slot->getFallbackMinuteOfDay());
        }

        return new FixedSlotStart($slot->getMinuteOfDay());
    }

    private function scheduleId(?Schedule $schedule): ?ScheduleId
    {
        return null === $schedule ? null : new ScheduleId($schedule->getId()->toRfc4122());
    }

    private function vacation(GlobalSettings $settings): ?VacationPeriod
    {
        $returnAt = $settings->getVacationReturnAt();
        if (!$settings->isVacationEnabled() || null === $returnAt) {
            return null;
        }

        try {
            // Without a start date the vacation is considered started since ever.
            return new VacationPeriod($settings->getVacationStartAt() ?? new \DateTimeImmutable('@0'), $returnAt, $settings->isOffDuringVacation());
        } catch (DomainExceptionInterface $e) {
            $this->logger->error('Vacation ignored, its configuration is invalid: {message}', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param list<string> $values
     *
     * @return list<EntityId>
     */
    private function entityIds(array $values): array
    {
        return array_map(static fn (string $value): EntityId => new EntityId($value), $values);
    }

    /**
     * Global settings are free text: an invalid entity id is reported and skipped instead of stopping the engine.
     *
     * @param list<string> $values
     *
     * @return list<EntityId>
     */
    private function lenientEntityIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            try {
                $ids[] = new EntityId($value);
            } catch (DomainExceptionInterface $e) {
                $this->logger->error('Entity id ignored in the global settings: {message}', ['message' => $e->getMessage()]);
            }
        }

        return $ids;
    }
}
