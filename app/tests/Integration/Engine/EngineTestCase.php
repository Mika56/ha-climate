<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Engine\Lifecycle\Engine;
use App\Application\Port\HomeAssistant\HomeAssistantConfig;
use App\Application\Port\HomeAssistant\StateContext;
use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Domain\Device\DeviceRole;
use App\Infrastructure\Persistence\Entity\AuditEntry;
use App\Infrastructure\Persistence\Entity\GlobalSettings;
use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Entity\Schedule;
use App\Infrastructure\Persistence\Enum\SlotStartType;
use App\Infrastructure\Persistence\Repository\AuditEntryRepository;
use App\Infrastructure\Persistence\Repository\GlobalSettingsRepository;
use App\Infrastructure\Persistence\Repository\PresetRepository;
use App\Infrastructure\Persistence\Seed\DefaultDataSeeder;
use App\Tests\Double\FakeHomeAssistantGateway;
use App\Tests\Double\ManualTimerDriver;
use App\Tests\Double\RecordedServiceCall;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Base of the engine scenarios: the real engine and its services from the container, wired (see
 * config/services.yaml, `when@test`) to a fake Home Assistant, a mock clock set to Monday 2026-10-26 07:00 UTC
 * (08:00 in Paris, where Home Assistant lives) and a timer driver that only moves when told to.
 *
 * The standard house is a living room with one heating device, `climate.living`, heated by a weekly
 * schedule (comfort 21 degrees from 06:30, night 17 from 22:30) and a temperature sensor.
 */
abstract class EngineTestCase extends KernelTestCase
{
    protected const string DEVICE = 'climate.living';
    protected const string SENSOR = 'sensor.living_temperature';

    private bool $engineStarted = false;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->homeAssistant()->withConfig(new HomeAssistantConfig('fr', 'Europe/Paris', '°C', '2026.10.0'));
        $this->service(DefaultDataSeeder::class)->seed();
    }

    protected function tearDown(): void
    {
        if ($this->engineStarted) {
            $this->engine()->stop();
        }
        parent::tearDown();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    protected function em(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class);
    }

    protected function engine(): Engine
    {
        return $this->service(Engine::class);
    }

    protected function homeAssistant(): FakeHomeAssistantGateway
    {
        return $this->service(FakeHomeAssistantGateway::class);
    }

    protected function clock(): MockClock
    {
        return $this->service(MockClock::class);
    }

    protected function timers(): ManualTimerDriver
    {
        return $this->service(ManualTimerDriver::class);
    }

    protected function setSeason(Season $season): void
    {
        $settings = $this->service(GlobalSettingsRepository::class)->get();
        $settings->changeSeason($season);
        $this->em()->flush();
    }

    protected function setPaused(bool $paused): void
    {
        $this->settings()->changePaused($paused);
        $this->em()->flush();
    }

    /**
     * A room with one device, the temperature sensor, a presence entity and one opening. Presets:
     * heat comfort 21 / eco 18 / night 17 / away 15, cool comfort 24 / eco 27 / night 26 / away 30. The
     * heating schedule goes comfort 06:30, night 22:30 every day; the cooling one (if requested) night 06:30
     * and comfort 18:00. With $nightAnchor, the 22:30 night slot starts at the time of that input_datetime instead.
     */
    protected function createRoom(
        string $name = 'Living room',
        string $device = self::DEVICE,
        DeviceRole $role = DeviceRole::Heating,
        string $sensor = self::SENSOR,
        bool $withCoolingSchedule = false,
        ?string $nightAnchor = null,
    ): Room {
        $presets = $this->service(PresetRepository::class);
        $comfort = $presets->findOneByKey('comfort') ?? throw new \LogicException('Seed missing.');
        $eco = $presets->findOneByKey('eco') ?? throw new \LogicException('Seed missing.');
        $night = $presets->findOneByKey('night') ?? throw new \LogicException('Seed missing.');
        $away = $presets->findOneByKey('away') ?? throw new \LogicException('Seed missing.');

        $room = new Room($name);
        $room->configureSensors($sensor, 'binary_sensor.'.strtolower(str_replace(' ', '_', $name)).'_presence', ['binary_sensor.'.strtolower(str_replace(' ', '_', $name)).'_window']);
        $room->addDevice($device, $role);
        foreach ([[$comfort, 21.0, 24.0], [$eco, 18.0, 27.0], [$night, 17.0, 26.0], [$away, 15.0, 30.0]] as [$preset, $heat, $cool]) {
            $room->setPresetTemperature($preset, HvacMode::Heat, $heat);
            $room->setPresetTemperature($preset, HvacMode::Cool, $cool);
        }

        $heating = new Schedule($name.' heating', HvacMode::Heat);
        $cooling = new Schedule($name.' cooling', HvacMode::Cool);
        foreach (range(1, 7) as $weekday) {
            $heating->addSlot($weekday, SlotStartType::Fixed, 6 * 60 + 30, $comfort);
            if (null === $nightAnchor) {
                $heating->addSlot($weekday, SlotStartType::Fixed, 22 * 60 + 30, $night);
            } else {
                $heating->addSlot($weekday, SlotStartType::Anchored, 22 * 60 + 30, $night, $nightAnchor, 0, 22 * 60 + 30);
            }
            $cooling->addSlot($weekday, SlotStartType::Fixed, 6 * 60 + 30, $night);
            $cooling->addSlot($weekday, SlotStartType::Fixed, 18 * 60, $comfort);
        }
        $room->assignSchedule(HvacMode::Heat, $heating);
        $this->em()->persist($heating);
        if ($withCoolingSchedule) {
            $room->assignSchedule(HvacMode::Cool, $cooling);
            $this->em()->persist($cooling);
        }
        $this->em()->persist($room);
        $this->em()->flush();

        return $room;
    }

    /**
     * The device in the given state, as Home Assistant would report it.
     */
    protected function seedDevice(string $entityId = self::DEVICE, string $mode = 'off', ?float $target = null): void
    {
        $this->homeAssistant()->seedState($entityId, $mode, [
            'hvac_modes' => ['off', 'heat', 'cool'],
            'temperature' => $target,
            'current_temperature' => 19.5,
        ]);
    }

    protected function seedSensor(string $entityId = self::SENSOR, string $state = '19.5'): void
    {
        $this->homeAssistant()->seedState($entityId, $state, ['unit_of_measurement' => '°C', 'device_class' => 'temperature']);
    }

    /**
     * Makes the device follow the service call, the way a real device reports the change: same context.
     */
    protected function deviceAppliesCommand(RecordedServiceCall $call): void
    {
        $entityId = $call->target['entity_id'];
        self::assertIsString($entityId);
        $mode = $call->serviceData['hvac_mode'] ?? null;
        self::assertIsString($mode);
        $current = $this->homeAssistant()->state($entityId);

        $this->homeAssistant()->pushStateChange(
            $entityId,
            $mode,
            [...($current->attributes ?? []), 'temperature' => $call->serviceData['temperature'] ?? ($current->attributes['temperature'] ?? null)],
            new StateContext($call->context->id),
        );
    }

    /**
     * @return list<AuditEntry> the audit entries with the given message key (all of them when null)
     *
     * @phpstan-impure
     */
    protected function auditEntries(?string $messageKey = null): array
    {
        $this->em()->clear();

        return array_values(array_filter(
            $this->service(AuditEntryRepository::class)->findAll(),
            static fn (AuditEntry $entry): bool => null === $messageKey || $entry->getMessageKey() === $messageKey,
        ));
    }

    /**
     * Deletes a room (the entity manager was cleared by the engine since it was created, hence the lookup).
     */
    protected function deleteRoom(Room $room): void
    {
        $managed = $this->em()->find(Room::class, $room->getId());
        self::assertInstanceOf(Room::class, $managed);
        $this->em()->remove($managed);
        $this->em()->flush();
    }

    /**
     * The entity a service call is aimed at.
     */
    protected static function targetOf(RecordedServiceCall $call): string
    {
        $entityId = $call->target['entity_id'] ?? null;
        self::assertIsString($entityId);

        return $entityId;
    }

    /**
     * A string parameter of an audit entry.
     */
    protected static function auditString(AuditEntry $entry, string $parameter): string
    {
        $value = $entry->getParams()[$parameter] ?? null;
        self::assertIsString($value);

        return $value;
    }

    protected function startEngine(): void
    {
        $this->engineStarted = true;
        $this->engine()->start();
    }

    protected function settings(): GlobalSettings
    {
        return $this->service(GlobalSettingsRepository::class)->get();
    }
}
