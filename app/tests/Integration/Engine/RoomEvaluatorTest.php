<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Engine\Evaluation\LiveStatusPublisher;
use App\Application\Engine\Evaluation\RoomEvaluator;
use App\Application\Engine\Lifecycle\ConfigurationReloader;
use App\Application\Engine\State\StateStore;
use App\Application\Engine\Timer\RoomReevaluateHandler;
use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\EntityStateChange;
use App\Application\Port\HomeAssistant\StateContext;
use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Domain\Device\DeviceRole;
use App\Domain\Room\RoomId;
use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Entity\RoomOverride;
use App\Infrastructure\Persistence\Entity\Timer;
use App\Infrastructure\Persistence\Repository\RoomOverrideRepository;
use App\Infrastructure\Persistence\Repository\TimerRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RoomEvaluator::class)]
#[CoversClass(RoomReevaluateHandler::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure')]
final class RoomEvaluatorTest extends EngineTestCase
{
    public function testTheScheduleBoundaryTimerSwitchesComfortToNightAndSendsOneCommand(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->startEngine();
        self::assertSame([], $this->homeAssistant()->serviceCalls());

        // Nothing happens before 22:30 Paris time (21:30 UTC)...
        $this->timers()->advance(14 * 3600);
        self::assertSame([], $this->homeAssistant()->serviceCalls());

        // ...then the night slot starts, with no event from Home Assistant: the timer alone does it.
        $this->timers()->advance(30 * 60);

        $calls = $this->homeAssistant()->serviceCalls();
        self::assertCount(1, $calls);
        self::assertSame(['temperature' => 17.0, 'hvac_mode' => 'heat'], $calls[0]->serviceData);
        self::assertSame('2026-10-26 21:30:00', $this->clock()->now()->format('Y-m-d H:i:s'));

        $changes = $this->auditEntries('audit.decision.changed');
        self::assertCount(1, $changes);
        self::assertSame(['mode' => 'heat', 'target' => 17.0], $changes[0]->getParams());
        self::assertSame('schedule', $changes[0]->getPayload()['cause']);
        self::assertSame('Living room', $changes[0]->getRoomName());
    }

    public function testTheBoundaryTimerIsRearmedForTheNextSlotAndSurvivesRepeatedEvaluations(): void
    {
        $this->setSeason(Season::Winter);
        $room = $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->startEngine();
        $key = 'room.boundary.'.$room->getId()->toRfc4122();

        $timer = $this->service(TimerRepository::class)->findOneByKey($key);
        self::assertInstanceOf(Timer::class, $timer);
        self::assertSame('2026-10-26 21:30:00', $timer->getDueAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertSame('room_reevaluate', $timer->getType());

        $pending = $this->timers()->pendingCount();
        $this->homeAssistant()->pushStateChange(self::SENSOR, '19.0');
        $this->homeAssistant()->pushStateChange(self::SENSOR, '18.5');
        self::assertSame($pending, $this->timers()->pendingCount(), 'An unchanged boundary is not rearmed.');

        $this->timers()->advance(14.5 * 3600);
        $this->em()->clear();
        $next = $this->service(TimerRepository::class)->findOneByKey($key);
        self::assertSame('2026-10-27 05:30:00', $next?->getDueAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    public function testASlotAnchoredOnAnInputDatetimeFollowsTheEntity(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom(nightAnchor: 'input_datetime.bedtime');
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->homeAssistant()->seedState('input_datetime.bedtime', '21:00:00', ['has_date' => false, 'has_time' => true, 'hour' => 21, 'minute' => 0]);
        $this->startEngine();

        // Bedtime moves to 22:00 Paris time (21:00 UTC): the night slot must start then, not at 21:00.
        $this->homeAssistant()->pushStateChange('input_datetime.bedtime', '22:00:00', ['has_date' => false, 'has_time' => true, 'hour' => 22, 'minute' => 0]);

        $this->timers()->advance(13.5 * 3600); // 20:30 UTC: the old 21:00 Paris boundary has passed
        self::assertSame([], $this->homeAssistant()->serviceCalls());

        $this->timers()->advance(31 * 60); // 21:01 UTC
        self::assertCount(1, $this->homeAssistant()->serviceCalls());
        self::assertSame(17.0, $this->homeAssistant()->serviceCalls()[0]->serviceData['temperature']);
    }

    public function testARuleDeadlineEarlierThanTheNextSlotArmsTheBoundaryTimerAtThatInstant(): void
    {
        $this->setSeason(Season::Winter);
        $room = $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $managed = $this->em()->find(Room::class, $room->getId());
        self::assertNotNull($managed);
        $now = $this->clock()->now();
        // The override rule asks to be evaluated again when the boost ends, at 08:30 Paris (07:30 UTC): before the 22:30 slot.
        $this->service(RoomOverrideRepository::class)->save(RoomOverride::boost($managed, HvacMode::Heat, 1.0, $now, $now->modify('+30 minutes'), false, 'alice'));
        $key = 'room.boundary.'.$room->getId()->toRfc4122();

        $this->startEngine();

        $timer = $this->service(TimerRepository::class)->findOneByKey($key);
        self::assertInstanceOf(Timer::class, $timer);
        self::assertSame('2026-10-26 07:30:00', $timer->getDueAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertSame('rule_deadline', $timer->getPayload()['cause']);

        // The deadline passes: the timer is rearmed for the schedule boundary with the schedule as cause.
        $this->timers()->advance(30 * 60);
        $this->em()->clear();
        $next = $this->service(TimerRepository::class)->findOneByKey($key);
        self::assertSame('2026-10-26 21:30:00', $next?->getDueAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertSame('schedule', $next->getPayload()['cause']);
    }

    public function testARuleDeadlineLaterThanTheNextSlotLeavesTheScheduleBoundary(): void
    {
        $this->setSeason(Season::Winter);
        $room = $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $managed = $this->em()->find(Room::class, $room->getId());
        self::assertNotNull($managed);
        $now = $this->clock()->now();
        $this->service(RoomOverrideRepository::class)->save(RoomOverride::boost($managed, HvacMode::Heat, 1.0, $now, $now->modify('+20 hours'), false, 'alice'));

        $this->startEngine();

        $timer = $this->service(TimerRepository::class)->findOneByKey('room.boundary.'.$room->getId()->toRfc4122());
        self::assertSame('2026-10-26 21:30:00', $timer?->getDueAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertSame('schedule', $timer->getPayload()['cause']);
    }

    public function testASeasonWithoutDeviceForItTurnsTheHeatersOff(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom('Living room', 'climate.living');
        $this->createRoom('Bedroom', 'climate.bedroom', sensor: 'sensor.bedroom_temperature');
        $this->seedDevice('climate.living', 'heat', 21.0);
        $this->seedDevice('climate.bedroom', 'heat', 21.0);
        $this->seedSensor();
        $this->startEngine();
        self::assertSame([], $this->homeAssistant()->serviceCalls());

        $this->setSeason(Season::Summer);
        $this->service(ConfigurationReloader::class)->reload();

        $calls = $this->homeAssistant()->serviceCalls();
        self::assertCount(2, $calls);
        foreach ($calls as $call) {
            self::assertSame('set_hvac_mode', $call->service);
            self::assertSame(['hvac_mode' => 'off'], $call->serviceData);
        }
        self::assertEqualsCanonicalizing(['climate.living', 'climate.bedroom'], array_map(static fn ($call) => $call->target['entity_id'], $calls));
        foreach ($this->service(LiveStatusPublisher::class)->snapshot() as $status) {
            self::assertSame('reason.no_device', $status->decision->reason->code);
            self::assertSame(HvacMode::Off, $status->decision->mode);
            self::assertFalse($status->paused);
        }
        self::assertCount(2, $this->auditEntries('audit.decision.changed'));
    }

    public function testACoolingOnlyDeviceIsTurnedOffInWinter(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom('Office', 'climate.office_ac', DeviceRole::Cooling, 'sensor.office_temperature');
        $this->seedDevice('climate.office_ac', 'cool', 24.0);
        $this->startEngine();

        $calls = $this->homeAssistant()->serviceCalls();
        self::assertCount(1, $calls);
        self::assertSame('set_hvac_mode', $calls[0]->service);
        self::assertSame(['hvac_mode' => 'off'], $calls[0]->serviceData);
        self::assertSame('reason.no_device', $this->service(LiveStatusPublisher::class)->snapshot()[0]->decision->reason->code);
    }

    public function testEachDeviceOfARoomIsDrivenAccordingToItsRole(): void
    {
        $this->setSeason(Season::Winter);
        $room = $this->createRoom('Loft', 'climate.loft_radiator', DeviceRole::Heating, 'sensor.loft_temperature');
        $room->addDevice('climate.loft_ac', DeviceRole::Cooling);
        $room->addDevice('climate.loft_heat_pump', DeviceRole::Reversible);
        $this->em()->flush();
        $this->seedDevice('climate.loft_radiator', 'off');
        $this->seedDevice('climate.loft_ac', 'cool', 24.0);
        $this->seedDevice('climate.loft_heat_pump', 'off');
        $this->startEngine();

        $byDevice = [];
        foreach ($this->homeAssistant()->serviceCalls() as $call) {
            $byDevice[self::targetOf($call)] = [$call->service, $call->serviceData];
        }
        ksort($byDevice);
        self::assertSame([
            'climate.loft_ac' => ['set_hvac_mode', ['hvac_mode' => 'off']],
            'climate.loft_heat_pump' => ['set_temperature', ['temperature' => 21.0, 'hvac_mode' => 'heat']],
            'climate.loft_radiator' => ['set_temperature', ['temperature' => 21.0, 'hvac_mode' => 'heat']],
        ], $byDevice);
    }

    public function testSummerCoolsAReversibleDeviceFollowingTheCoolingSchedule(): void
    {
        $this->setSeason(Season::Summer);
        $this->createRoom(role: DeviceRole::Reversible, withCoolingSchedule: true);
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->startEngine();

        $calls = $this->homeAssistant()->serviceCalls();
        self::assertCount(1, $calls);
        // 08:00 Paris time is inside the cooling schedule's night slot (from 06:30).
        self::assertSame(['temperature' => 26.0, 'hvac_mode' => 'cool'], $calls[0]->serviceData);
    }

    public function testARoomWithoutScheduleForTheSeasonIsSwitchedOff(): void
    {
        $this->setSeason(Season::Summer);
        $this->createRoom(role: DeviceRole::Reversible);
        $this->seedDevice(mode: 'cool', target: 24.0);
        $this->startEngine();

        self::assertSame(['hvac_mode' => 'off'], $this->homeAssistant()->serviceCalls()[0]->serviceData);
        self::assertSame('reason.no_schedule', $this->service(LiveStatusPublisher::class)->snapshot()[0]->decision->reason->code);
        self::assertCount(0, $this->service(TimerRepository::class)->findBy(['type' => 'room_reevaluate']), 'No schedule, no boundary to wait for.');
    }

    public function testARoomThatCannotBeEvaluatedIsReportedOnceAndDoesNotStopTheOthers(): void
    {
        $this->setSeason(Season::Winter);
        $broken = $this->createRoom('Broken', 'climate.broken', sensor: 'sensor.broken_temperature');
        foreach ($broken->getPresetTemperatures() as $temperature) {
            if (HvacMode::Heat === $temperature->getMode() && 'comfort' === $temperature->getPreset()->getKey()) {
                $broken->removePresetTemperature($temperature);
            }
        }
        $this->em()->flush();
        $this->createRoom('Fine', 'climate.fine', sensor: 'sensor.fine_temperature');
        $this->seedDevice('climate.broken', 'off');
        $this->seedDevice('climate.fine', 'off');
        $this->startEngine();

        self::assertCount(1, $this->homeAssistant()->serviceCalls());
        self::assertSame('climate.fine', $this->homeAssistant()->serviceCalls()[0]->target['entity_id']);

        $this->homeAssistant()->pushStateChange('climate.broken', 'off', ['temperature' => 7.0]);
        $failures = $this->auditEntries('audit.evaluation.failed');
        self::assertCount(1, $failures, 'The same problem is reported once.');
        self::assertStringContainsString('comfort', self::auditString($failures[0], 'error'));
    }

    public function testAnUnknownRoomIsIgnored(): void
    {
        $this->setSeason(Season::Winter);
        $this->startEngine();

        $this->service(RoomEvaluator::class)->evaluate([new RoomId('ghost')], 'test');

        self::assertSame([], $this->homeAssistant()->serviceCalls());
        self::assertSame([], $this->service(LiveStatusPublisher::class)->snapshot());
    }

    public function testForgettingARoomDropsItsStatusAndBoundaryTimer(): void
    {
        $this->setSeason(Season::Winter);
        $room = $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->startEngine();
        $roomId = new RoomId($room->getId()->toRfc4122());

        $this->service(RoomEvaluator::class)->forget($roomId);

        self::assertSame([], $this->service(LiveStatusPublisher::class)->snapshot());
        self::assertNull($this->service(TimerRepository::class)->findOneByKey('room.boundary.'.$roomId));
    }

    public function testTheTimerHandlerEvaluatesTheRoomOfTheTimerWithItsCause(): void
    {
        $this->setSeason(Season::Winter);
        $room = $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->startEngine();
        $handler = $this->service(RoomReevaluateHandler::class);
        self::assertSame('room_reevaluate', $handler->type());

        // The device is found off in the store without any evaluation being triggered: only the timer makes the engine act.
        $this->deviceSeenOff();
        $handler->handle(new Timer('manual', 'room_reevaluate', $this->clock()->now(), Uuid::fromString($room->getId()->toRfc4122()), ['cause' => 'custom']));
        self::assertCount(1, $this->homeAssistant()->serviceCalls());

        $this->homeAssistant()->clearServiceCalls();
        $this->clock()->sleep(60);
        $this->deviceSeenOff();
        $handler->handle(new Timer('manual-2', 'room_reevaluate', $this->clock()->now(), null));
        self::assertSame([], $this->homeAssistant()->serviceCalls(), 'A timer without a room does nothing.');

        $handler->handle(new Timer('manual-3', 'room_reevaluate', $this->clock()->now(), Uuid::fromString($room->getId()->toRfc4122()), ['cause' => 42]));
        self::assertCount(1, $this->homeAssistant()->serviceCalls(), 'A timer without a usable cause still evaluates the room.');
    }

    private function deviceSeenOff(): void
    {
        $this->service(StateStore::class)->apply(new EntityStateChange(self::DEVICE, null, new EntityState(self::DEVICE, 'off', ['temperature' => 7.0], new \DateTimeImmutable(), new \DateTimeImmutable(), new StateContext('elsewhere'))));
    }
}
