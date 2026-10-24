<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Engine\Configuration\ConfigurationLoader;
use App\Application\Engine\Configuration\ConfigurationRepository;
use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Domain\Device\DeviceRole;
use App\Domain\Preset\PresetKey;
use App\Domain\Room\RoomId;
use App\Domain\Schedule\AnchoredSlotStart;
use App\Domain\Schedule\FixedSlotStart;
use App\Domain\Schedule\ScheduleId;
use App\Infrastructure\Persistence\Entity\Room;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;

#[CoversClass(ConfigurationRepository::class)]
#[CoversClass(ConfigurationLoader::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure')]
final class ConfigurationRepositoryTest extends EngineTestCase
{
    public function testMapsTheDatabaseOntoDomainObjects(): void
    {
        $this->setSeason(Season::Summer);
        $this->settings()->configurePresence(2400, ['person.alice', 'person.bob']);
        $this->settings()->changeWeatherEntity('weather.home');
        $this->settings()->changeAuditRetentionDays(45);
        $this->settings()->changeManualOverrideMinutes(90);
        $room = $this->createRoom(role: DeviceRole::Reversible, withCoolingSchedule: true, nightAnchor: 'input_datetime.bedtime');
        $room->configureDelays(600, 90, 1200);
        $room->configurePreheat(true, 45);
        $this->em()->flush();
        $configuration = $this->service(ConfigurationRepository::class);

        $rooms = $configuration->rooms();

        self::assertCount(1, $rooms);
        $living = $rooms[0];
        self::assertSame($room->getId()->toRfc4122(), (string) $living->id);
        self::assertSame('Living room', $living->name);
        self::assertSame(self::DEVICE, (string) $living->devices[0]->entityId);
        self::assertSame(DeviceRole::Reversible, $living->devices[0]->role);
        self::assertSame(21.0, $living->presetTemperatures->for(new PresetKey('comfort'), HvacMode::Heat)->toFloat());
        self::assertSame(26.0, $living->presetTemperatures->for(new PresetKey('night'), HvacMode::Cool)->toFloat());
        self::assertSame(self::SENSOR, (string) $living->temperatureSensor);
        self::assertSame('binary_sensor.living_room_presence', (string) $living->presence);
        self::assertSame(['binary_sensor.living_room_window'], array_map(strval(...), $living->openings));
        self::assertSame([600, 90, 1200], [$living->vacancyDelay, $living->windowCutDelay, $living->windowNotifyDelay]);
        self::assertTrue($living->preheatEnabled);
        self::assertSame(45, $living->preheatMaxMinutes);

        self::assertSame(Season::Summer, $configuration->season());
        self::assertSame(2400, $configuration->policy()->houseEmptyDelaySeconds);
        self::assertSame(['person.alice', 'person.bob'], array_map(strval(...), $configuration->trackedPersons()));
        self::assertSame('weather.home', (string) $configuration->weatherEntity());
        self::assertNull($configuration->vacation());
        self::assertSame(45, $configuration->snapshot()->auditRetentionDays);
        self::assertSame(90, $configuration->snapshot()->manualOverrideMinutes);
        self::assertSame($living, $configuration->room($living->id));
        self::assertNull($configuration->room(new RoomId('ghost')));
    }

    public function testMapsSchedulesWithFixedAndAnchoredSlots(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom(nightAnchor: 'input_datetime.bedtime');
        $configuration = $this->service(ConfigurationRepository::class);
        $scheduleId = $configuration->rooms()[0]->heatingSchedule;
        self::assertInstanceOf(ScheduleId::class, $scheduleId);

        $schedule = $configuration->schedule($scheduleId);

        self::assertNotNull($schedule);
        self::assertSame(HvacMode::Heat, $schedule->mode);
        self::assertCount(14, $schedule->slots);
        $starts = array_map(static fn ($slot): string => $slot->start::class, $schedule->slots);
        self::assertContains(FixedSlotStart::class, $starts);
        self::assertContains(AnchoredSlotStart::class, $starts);
        $anchoredSlots = array_values(array_filter($schedule->slots, static fn ($slot): bool => $slot->start instanceof AnchoredSlotStart));
        $anchored = ($anchoredSlots[0] ?? null)?->start;
        self::assertInstanceOf(AnchoredSlotStart::class, $anchored);
        self::assertSame('input_datetime.bedtime', (string) $anchored->anchor);
        self::assertNull($configuration->rooms()[0]->coolingSchedule);
        self::assertNull($configuration->schedule(new ScheduleId('ghost')));
    }

    public function testMapsAnActiveVacation(): void
    {
        $settings = $this->settings();
        $settings->configureVacation(true, $this->clock()->now()->modify('-1 day'), $this->clock()->now()->modify('+7 days'), true);
        $this->em()->flush();

        $vacation = $this->service(ConfigurationRepository::class)->vacation();

        self::assertNotNull($vacation);
        self::assertTrue($vacation->offDuringVacation);
        self::assertTrue($vacation->isActiveAt($this->clock()->now()));
    }

    public function testAVacationWithoutStartHasAlreadyStarted(): void
    {
        $this->settings()->configureVacation(true, null, $this->clock()->now()->modify('+7 days'), false);
        $this->em()->flush();

        $vacation = $this->service(ConfigurationRepository::class)->vacation();

        self::assertTrue($vacation?->isActiveAt($this->clock()->now()));
    }

    public function testADisabledVacationIsIgnored(): void
    {
        $this->settings()->configureVacation(false, $this->clock()->now(), $this->clock()->now()->modify('+7 days'), true);
        $this->em()->flush();

        self::assertNull($this->service(ConfigurationRepository::class)->vacation());
    }

    public function testInvalidRecordsAreLeftOutInsteadOfStoppingTheEngine(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom('Fine', 'climate.fine', sensor: 'sensor.fine_temperature');
        $broken = $this->createRoom('Broken', 'climate.broken', sensor: 'sensor.broken_temperature');
        $broken->setPresetTemperature($this->em()->getRepository(\App\Infrastructure\Persistence\Entity\Preset::class)->findOneBy(['key' => 'eco']) ?? throw new \LogicException(), HvacMode::Heat, 99.0);
        $typo = $this->createRoom('Typo', 'climate.typo', sensor: 'sensor.typo_temperature');
        $typo->configureSensors('Not An Entity', null, []);
        $this->settings()->configurePresence(600, ['person.alice', 'bogus']);
        $this->settings()->changeWeatherEntity('also bogus');
        $this->em()->flush();

        $configuration = $this->service(ConfigurationRepository::class);

        self::assertSame(['Fine'], array_map(static fn ($room): string => $room->name, $configuration->rooms()));
        self::assertSame(['person.alice'], array_map(strval(...), $configuration->trackedPersons()));
        self::assertNull($configuration->weatherEntity());
    }

    public function testAScheduleWithoutSlotsIsSkipped(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->em()->persist(new \App\Infrastructure\Persistence\Entity\Schedule('Empty', HvacMode::Heat));
        $this->em()->flush();
        $configuration = $this->service(ConfigurationRepository::class);

        self::assertCount(1, $configuration->snapshot()->schedules);
    }

    public function testTheConfigurationIsCachedUntilReloaded(): void
    {
        $this->setSeason(Season::Winter);
        $room = $this->createRoom();
        $configuration = $this->service(ConfigurationRepository::class);
        $cached = $configuration->snapshot();
        self::assertSame($cached, $configuration->snapshot());

        $this->createRoom('Bedroom', 'climate.bedroom', sensor: 'sensor.bedroom_temperature');
        $this->setSeason(Season::Summer);
        self::assertSame($cached, $configuration->snapshot(), 'Cached in memory.');
        self::assertSame(Season::Winter, $configuration->season());

        $managed = $this->em()->find(Room::class, $room->getId());
        self::assertNotNull($managed);

        $reloaded = $configuration->reload();

        self::assertCount(2, $reloaded->rooms);
        self::assertSame(Season::Summer, $configuration->season());
        self::assertSame($reloaded, $configuration->snapshot());
        self::assertFalse($this->em()->contains($managed), 'The entity manager was cleared on reload.');
    }
}
