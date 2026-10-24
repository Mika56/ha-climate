<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Engine\Evaluation\LiveStatusPublisher;
use App\Application\Engine\Facts\HeatingRateContributor;
use App\Application\Engine\Facts\HouseEmptyContributor;
use App\Application\Engine\Facts\RoomVacancyContributor;
use App\Application\Engine\Facts\VacationContributor;
use App\Application\Engine\Heating\HeatingRateLearner;
use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Domain\Decision\Rule\HouseEmptyRule;
use App\Domain\Decision\Rule\PreheatRule;
use App\Domain\Decision\Rule\RoomVacancyRule;
use App\Domain\Decision\Rule\VacationRule;
use App\Infrastructure\Persistence\Entity\HeatingRate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;

/**
 * The engine starts on Monday 2026-10-26 08:00 Paris time; the room follows comfort 21 from 06:30 and night 17
 * from 22:30. Nothing here pushes an event to make a delay elapse: the timers alone do it.
 */
#[CoversClass(HouseEmptyRule::class)]
#[CoversClass(RoomVacancyRule::class)]
#[CoversClass(VacationRule::class)]
#[CoversClass(PreheatRule::class)]
#[CoversClass(HouseEmptyContributor::class)]
#[CoversClass(RoomVacancyContributor::class)]
#[CoversClass(VacationContributor::class)]
#[CoversClass(HeatingRateContributor::class)]
#[CoversClass(HeatingRateLearner::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure')]
final class PresenceVacationPreheatScenarioTest extends EngineTestCase
{
    private const string PRESENCE = 'binary_sensor.living_room_presence';

    protected function setUp(): void
    {
        parent::setUp();
        // Presence delays count from `last_changed`, which must therefore follow the test clock.
        $this->homeAssistant()->followingClock(fn (): \DateTimeImmutable => $this->clock()->now());
    }

    public function testTheLastPersonLeavingSwitchesTheRoomOffExactlyAfterTheDelay(): void
    {
        $this->setSeason(Season::Winter);
        $this->settings()->configurePresence(1800, ['person.alice', 'person.bob']);
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->homeAssistant()->seedState('person.alice', 'home');
        $this->homeAssistant()->seedState('person.bob', 'home');
        $this->startEngine();
        self::assertSame([], $this->homeAssistant()->serviceCalls());

        $this->homeAssistant()->pushStateChange('person.alice', 'not_home');
        $this->timers()->advance(10 * 60);
        $this->homeAssistant()->pushStateChange('person.bob', 'not_home');

        // Alice left at 08:00, Bob at 08:10: the house has been empty since 08:10, so off at 08:40.
        $this->timers()->advance(30 * 60 - 1);
        self::assertSame([], $this->homeAssistant()->serviceCalls());

        $this->timers()->advance(1);
        $calls = $this->homeAssistant()->serviceCalls();
        self::assertCount(1, $calls);
        self::assertSame(['hvac_mode' => 'off'], $calls[0]->serviceData);
        self::assertSame('2026-10-26 07:40:00', $this->clock()->now()->format('Y-m-d H:i:s'));
        self::assertSame('reason.house_empty', $this->service(LiveStatusPublisher::class)->snapshot()[0]->decision->reason->code);
    }

    public function testSomeoneComingBackResumesTheSchedule(): void
    {
        $this->setSeason(Season::Winter);
        $this->settings()->configurePresence(1800, ['person.alice']);
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->homeAssistant()->seedState('person.alice', 'not_home');
        $this->startEngine();

        $this->timers()->advance(1800);
        $off = $this->homeAssistant()->serviceCalls();
        self::assertCount(1, $off);
        self::assertSame(['hvac_mode' => 'off'], $off[0]->serviceData);
        $this->deviceAppliesCommand($off[0]);
        $this->homeAssistant()->clearServiceCalls();

        $this->homeAssistant()->pushStateChange('person.alice', 'home');

        $calls = $this->homeAssistant()->serviceCalls();
        self::assertCount(1, $calls);
        self::assertSame(['temperature' => 21.0, 'hvac_mode' => 'heat'], $calls[0]->serviceData);
        self::assertSame('reason.schedule', $this->service(LiveStatusPublisher::class)->snapshot()[0]->decision->reason->code);
    }

    public function testAVacantRoomFallsBackToEcoAfterItsDelayAndBackWhenSomeoneEnters(): void
    {
        $this->setSeason(Season::Winter);
        $room = $this->createRoom();
        $room->configureDelays(600, 120, 1800);
        $this->em()->flush();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->homeAssistant()->seedState(self::PRESENCE, 'on');
        $this->startEngine();

        $this->homeAssistant()->pushStateChange(self::PRESENCE, 'off');
        $this->timers()->advance(599);
        self::assertSame([], $this->homeAssistant()->serviceCalls());

        $this->timers()->advance(1);
        $calls = $this->homeAssistant()->serviceCalls();
        self::assertCount(1, $calls);
        self::assertSame(['temperature' => 18.0, 'hvac_mode' => 'heat'], $calls[0]->serviceData);
        self::assertSame('reason.room_vacant', $this->service(LiveStatusPublisher::class)->snapshot()[0]->decision->reason->code);
        $this->deviceAppliesCommand($calls[0]);
        $this->homeAssistant()->clearServiceCalls();

        $this->homeAssistant()->pushStateChange(self::PRESENCE, 'on');

        self::assertSame(['temperature' => 21.0, 'hvac_mode' => 'heat'], $this->homeAssistant()->serviceCalls()[0]->serviceData);
    }

    public function testDuringAVacationTheRoomIsOffThenTheScheduleRestartsBeforeTheReturn(): void
    {
        $this->setSeason(Season::Winter);
        $room = $this->createRoom();
        $room->configurePreheat(true, 120);
        // From Monday 12:00 Paris (10:00 UTC) to Friday 18:00 Paris (17:00 UTC, summer time ended on the 25th).
        $this->settings()->configureVacation(true, new \DateTimeImmutable('2026-10-26 10:00:00 UTC'), new \DateTimeImmutable('2026-10-30 17:00:00 UTC'), true);
        $this->em()->flush();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->startEngine();
        self::assertSame([], $this->homeAssistant()->serviceCalls());

        $this->timers()->advance(3 * 3600 - 1);
        self::assertSame([], $this->homeAssistant()->serviceCalls(), 'Not started yet.');
        $this->timers()->advance(1);
        $off = $this->homeAssistant()->serviceCalls();
        self::assertCount(1, $off);
        self::assertSame(['hvac_mode' => 'off'], $off[0]->serviceData);
        self::assertSame('reason.vacation', $this->service(LiveStatusPublisher::class)->snapshot()[0]->decision->reason->code);
        $this->deviceAppliesCommand($off[0]);
        $this->homeAssistant()->clearServiceCalls();

        // The sensor reads 19.5 and the away preset is 15: 6 degrees to climb at the assumed 1 degree per hour,
        // more than the two hours the room allows, so the schedule resumes two hours before the return (16:00 Paris).
        $this->timers()->advance(4 * 24 * 3600 + 5 * 3600 - 1);
        self::assertSame([], $this->homeAssistant()->serviceCalls());
        $this->timers()->advance(1);

        $calls = $this->homeAssistant()->serviceCalls();
        self::assertCount(1, $calls);
        self::assertSame(['temperature' => 21.0, 'hvac_mode' => 'heat'], $calls[0]->serviceData);
        self::assertSame('2026-10-30 15:00:00', $this->clock()->now()->format('Y-m-d H:i:s'));
    }

    public function testThePreheatStartsEarlyEnoughToReachComfortAtTheSlotStart(): void
    {
        $this->setSeason(Season::Winter);
        $room = $this->createRoom();
        $room->configurePreheat(true, 240);
        $this->em()->flush();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor(state: '17.0');
        $this->startEngine();

        // Night begins at 22:30 Paris time (21:30 UTC).
        $this->timers()->advance(14.5 * 3600);
        $night = $this->homeAssistant()->serviceCalls();
        self::assertCount(1, $night);
        self::assertSame(['temperature' => 17.0, 'hvac_mode' => 'heat'], $night[0]->serviceData);
        $this->deviceAppliesCommand($night[0]);
        $this->homeAssistant()->clearServiceCalls();

        // Comfort is 4 degrees warmer and the room is assumed to gain 1 degree per hour: four hours of lead,
        // so 02:30 Paris time (01:30 UTC), with no event from Home Assistant.
        $this->timers()->advance(4 * 3600 - 1);
        self::assertSame([], $this->homeAssistant()->serviceCalls());
        $this->timers()->advance(1);

        $calls = $this->homeAssistant()->serviceCalls();
        self::assertCount(1, $calls);
        self::assertSame(['temperature' => 21.0, 'hvac_mode' => 'heat'], $calls[0]->serviceData);
        self::assertSame('2026-10-27 01:30:00', $this->clock()->now()->format('Y-m-d H:i:s'));
        self::assertSame('reason.preheat', $this->service(LiveStatusPublisher::class)->snapshot()[0]->decision->reason->code);
    }

    public function testALearnedRateShortensThePreheat(): void
    {
        $this->setSeason(Season::Winter);
        $room = $this->createRoom();
        $room->configurePreheat(true, 240);
        $this->em()->persist(new HeatingRate($room, HvacMode::Heat, 4.0, $this->clock()->now()));
        $this->em()->flush();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor(state: '17.0');
        $this->startEngine();
        $this->timers()->advance(14.5 * 3600);
        $this->deviceAppliesCommand($this->homeAssistant()->serviceCalls()[0]);
        $this->homeAssistant()->clearServiceCalls();

        // 4 degrees at 4 degrees per hour: one hour of lead, 05:30 Paris time (04:30 UTC).
        $this->timers()->advance(7 * 3600 - 1);
        self::assertSame([], $this->homeAssistant()->serviceCalls());
        $this->timers()->advance(1);

        self::assertSame(21.0, $this->homeAssistant()->serviceCalls()[0]->serviceData['temperature']);
        self::assertSame('2026-10-27 04:30:00', $this->clock()->now()->format('Y-m-d H:i:s'));
    }

    public function testTheEngineLearnsTheRateOfARoomFromItsDeviceAndSensor(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor(state: '19.0');
        $this->startEngine();

        // The room warms from 19 to 21 degrees in one hour; each reading is an event, hence an evaluation.
        $this->timers()->advance(1800);
        $this->homeAssistant()->pushStateChange(self::SENSOR, '20.0');
        $this->timers()->advance(1800);
        $this->homeAssistant()->pushStateChange(self::SENSOR, '21.0');

        $rates = $this->em()->getRepository(HeatingRate::class)->findAll();
        self::assertCount(1, $rates);
        self::assertEqualsWithDelta(2.0, $rates[0]->getDegreesPerHour(), 0.0001);
        self::assertSame(HvacMode::Heat, $rates[0]->getMode());
    }
}
