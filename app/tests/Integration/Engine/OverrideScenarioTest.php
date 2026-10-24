<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Engine\Override\ManualOverrideSubscriber;
use App\Application\Engine\Override\OverrideCommands;
use App\Application\Engine\Override\OverrideManager;
use App\Application\Port\HomeAssistant\HomeAssistantConfig;
use App\Application\Port\HomeAssistant\StateContext;
use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Domain\Climate\TemperatureDelta;
use App\Domain\Decision\OverrideType;
use App\Domain\Room\RoomId;
use App\Infrastructure\Persistence\Entity\RoomOverride;
use App\Infrastructure\Persistence\Enum\ActorType;
use App\Infrastructure\Persistence\Repository\RoomOverrideRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;

/**
 * Boosts and manual overrides end to end: real engine, fake Home Assistant, mock clock (Monday 08:00 in Paris,
 * heating schedule comfort 21 from 06:30, night 17 from 22:30) and timers that only move when told to.
 */
#[CoversClass(OverrideManager::class)]
#[CoversClass(OverrideCommands::class)]
#[CoversClass(ManualOverrideSubscriber::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure')]
final class OverrideScenarioTest extends EngineTestCase
{
    private RoomId $roomId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setSeason(Season::Winter);
        $this->roomId = new RoomId($this->createRoom()->getId()->toRfc4122());
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->startEngine();
    }

    public function testABoostRaisesTheTargetThenTheScheduleResumesWithoutAnyHomeAssistantEvent(): void
    {
        $status = $this->service(OverrideCommands::class)->boost($this->roomId, new TemperatureDelta(1.5), 60, false, 'alice');

        self::assertSame(22.5, $status->decision->target?->toFloat());
        self::assertSame('reason.boost', $status->decision->reason->code);
        self::assertSame(OverrideType::Boost, $status->override?->type);
        $calls = $this->homeAssistant()->serviceCalls();
        self::assertCount(1, $calls);
        self::assertSame(['temperature' => 22.5, 'hvac_mode' => 'heat'], $calls[0]->serviceData);
        $this->deviceAppliesCommand($calls[0]);
        $this->homeAssistant()->clearServiceCalls();

        $this->timers()->advance(59 * 60);
        self::assertSame([], $this->homeAssistant()->serviceCalls(), 'The boost is still running.');

        $this->timers()->advance(60);
        $calls = $this->homeAssistant()->serviceCalls();
        self::assertCount(1, $calls);
        self::assertSame(['temperature' => 21.0, 'hvac_mode' => 'heat'], $calls[0]->serviceData);
        self::assertNull($this->roomStatus()->override);

        $started = $this->auditEntries('audit.override.boost_started');
        self::assertCount(1, $started);
        self::assertSame(ActorType::User, $started[0]->getActorType());
        self::assertSame('alice', $started[0]->getActorId());
        self::assertSame(1.5, $started[0]->getParams()['delta']);
        self::assertSame('2026-10-26T09:00:00+01:00', $started[0]->getParams()['until']);
    }

    public function testABoostUntilTheNextSlotEndsWhenTheNightSlotStarts(): void
    {
        $status = $this->service(OverrideCommands::class)->boost($this->roomId, new TemperatureDelta(1.0), null, true, 'alice');

        self::assertSame(22.0, $status->decision->target?->toFloat());
        self::assertTrue($status->override?->untilNextSlot);
        self::assertSame('2026-10-26T21:30:00+00:00', $status->override->endsAt?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
        $this->deviceAppliesCommand($this->homeAssistant()->serviceCalls()[0]);
        $this->homeAssistant()->clearServiceCalls();

        $this->timers()->advance(14.5 * 3600 - 1);
        self::assertSame([], $this->homeAssistant()->serviceCalls());

        $this->timers()->advance(1);
        $calls = $this->homeAssistant()->serviceCalls();
        self::assertCount(1, $calls, 'The boost ends and the night slot starts at the same instant: one command.');
        self::assertSame(17.0, $calls[0]->serviceData['temperature']);
    }

    public function testTheEarlierOfTheDurationAndTheNextSlotEndsTheBoost(): void
    {
        $short = $this->service(OverrideCommands::class)->boost($this->roomId, new TemperatureDelta(1.0), 30, true, 'alice');
        self::assertSame('2026-10-26T07:30:00+00:00', $short->override?->endsAt?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));

        $long = $this->service(OverrideCommands::class)->boost($this->roomId, new TemperatureDelta(1.0), 1000, true, 'alice');
        self::assertSame('2026-10-26T21:30:00+00:00', $long->override?->endsAt?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
        self::assertCount(1, $this->service(RoomOverrideRepository::class)->findAll(), 'A new boost replaces the previous override.');
    }

    public function testCancellingABoostPutsTheScheduleBackAtOnce(): void
    {
        $this->service(OverrideCommands::class)->boost($this->roomId, new TemperatureDelta(2.0), 120, false, 'alice');
        $this->deviceAppliesCommand($this->homeAssistant()->serviceCalls()[0]);
        $this->homeAssistant()->clearServiceCalls();

        $status = $this->service(OverrideCommands::class)->cancel($this->roomId, 'bob');

        self::assertNull($status->override);
        self::assertSame(21.0, $status->decision->target?->toFloat());
        self::assertSame(['temperature' => 21.0, 'hvac_mode' => 'heat'], $this->homeAssistant()->serviceCalls()[0]->serviceData);
        $cancelled = $this->auditEntries('audit.override.cancelled');
        self::assertCount(1, $cancelled);
        self::assertSame('boost', $cancelled[0]->getParams()['type']);
        self::assertSame('bob', $cancelled[0]->getActorId());
        self::assertSame([], $this->service(RoomOverrideRepository::class)->findAll());
    }

    public function testAManualChangeOnTheDeviceIsKeptThenTheScheduleResumes(): void
    {
        $this->homeAssistant()->pushStateChange(self::DEVICE, 'heat', ['temperature' => 24.0, 'current_temperature' => 19.5], new StateContext('ui-1', null, 'user-42'));

        self::assertSame([], $this->homeAssistant()->serviceCalls(), 'The engine keeps the user\'s setting instead of reverting it.');
        $status = $this->roomStatus();
        self::assertSame('reason.manual_override', $status->decision->reason->code);
        self::assertSame(24.0, $status->decision->target?->toFloat());
        self::assertSame(OverrideType::Manual, $status->override?->type);

        $overrides = $this->overrides();
        self::assertCount(1, $overrides);
        self::assertSame(24.0, $overrides[0]->getAbsoluteTarget());
        self::assertSame(HvacMode::Heat, $overrides[0]->getMode());
        self::assertSame('user-42', $overrides[0]->getActor());
        $endsAt = $overrides[0]->getEndsAt();
        self::assertNotNull($endsAt);
        self::assertSame(120 * 60, $endsAt->getTimestamp() - $overrides[0]->getStartedAt()->getTimestamp());

        $audit = $this->auditEntries('audit.override.manual_started');
        self::assertCount(1, $audit);
        self::assertSame(ActorType::User, $audit[0]->getActorType());
        self::assertSame(['mode' => 'heat', 'until' => '2026-10-26T10:00:00+01:00', 'target' => 24.0], $audit[0]->getParams());

        $this->timers()->advance(119 * 60);
        self::assertSame([], $this->homeAssistant()->serviceCalls());

        $this->timers()->advance(60);
        $calls = $this->homeAssistant()->serviceCalls();
        self::assertCount(1, $calls);
        self::assertSame(['temperature' => 21.0, 'hvac_mode' => 'heat'], $calls[0]->serviceData);
    }

    public function testAnotherManualChangeRestartsTheCountdownWithTheNewTarget(): void
    {
        $this->homeAssistant()->pushStateChange(self::DEVICE, 'heat', ['temperature' => 24.0], new StateContext('ui-1', null, 'user-42'));
        $this->timers()->advance(60 * 60);
        $this->homeAssistant()->pushStateChange(self::DEVICE, 'heat', ['temperature' => 19.0], new StateContext('ui-2', null, 'user-42'));

        self::assertSame([], $this->homeAssistant()->serviceCalls());
        self::assertSame(19.0, $this->roomStatus()->decision->target?->toFloat());
        self::assertCount(1, $this->overrides());

        $this->timers()->advance(119 * 60);
        self::assertSame([], $this->homeAssistant()->serviceCalls(), 'The first countdown no longer applies.');
        $this->timers()->advance(60);
        self::assertSame(21.0, $this->homeAssistant()->serviceCalls()[0]->serviceData['temperature']);
    }

    public function testSwitchingTheDeviceOffByHandKeepsItOffThenTheScheduleResumes(): void
    {
        $this->homeAssistant()->pushStateChange(self::DEVICE, 'off', ['temperature' => 21.0], new StateContext('physical-1'));

        self::assertSame([], $this->homeAssistant()->serviceCalls());
        self::assertSame(HvacMode::Off, $this->roomStatus()->decision->mode);
        self::assertSame('reason.manual_override', $this->roomStatus()->decision->reason->code);
        $audit = $this->auditEntries('audit.override.manual_started');
        self::assertSame(ActorType::Device, $audit[0]->getActorType());
        self::assertSame(self::DEVICE, $audit[0]->getActorId());
        self::assertSame(['mode' => 'off', 'until' => '2026-10-26T10:00:00+01:00'], $audit[0]->getParams());
        self::assertNull($this->overrides()[0]->getAbsoluteTarget());

        $this->timers()->advance(2 * 3600);

        self::assertSame(['temperature' => 21.0, 'hvac_mode' => 'heat'], $this->homeAssistant()->serviceCalls()[0]->serviceData);
    }

    public function testAManualModeThatIsNotTheSeasonsIsRecordedButTheSeasonWins(): void
    {
        $this->homeAssistant()->pushStateChange(self::DEVICE, 'cool', ['temperature' => 24.0], new StateContext('ui-1', null, 'user-42'));

        $overrides = $this->overrides();
        self::assertCount(1, $overrides);
        self::assertSame(HvacMode::Cool, $overrides[0]->getMode());
        self::assertNull($this->roomStatus()->override);
        self::assertSame(['temperature' => 21.0, 'hvac_mode' => 'heat'], $this->homeAssistant()->serviceCalls()[0]->serviceData);
    }

    public function testTheEnginesOwnCommandsAreNotTakenForManualChanges(): void
    {
        $this->timers()->advance(14.5 * 3600);
        $calls = $this->homeAssistant()->serviceCalls();
        self::assertSame(17.0, $calls[0]->serviceData['temperature']);

        $this->deviceAppliesCommand($calls[0]);

        self::assertSame([], $this->overrides());
        self::assertSame([], $this->auditEntries('audit.override.manual_started'));
        self::assertSame([], $this->auditEntries('audit.manual_change.detected'));
    }

    public function testARestartKeepsTheActiveBoostAndItsEnd(): void
    {
        $this->service(OverrideCommands::class)->boost($this->roomId, new TemperatureDelta(1.5), 60, false, 'alice');
        $this->deviceAppliesCommand($this->homeAssistant()->serviceCalls()[0]);
        $this->engine()->stop();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->homeAssistant()->withConfig(new HomeAssistantConfig('fr', 'Europe/Paris', '°C', '2026.10.0'));
        $this->seedDevice(mode: 'heat', target: 22.5);
        $this->seedSensor();

        $this->startEngine();

        self::assertSame([], $this->homeAssistant()->serviceCalls(), 'The restarted engine finds the boost and the device already in line.');
        self::assertSame(OverrideType::Boost, $this->roomStatus()->override?->type);
        $this->timers()->advance(59 * 60);
        self::assertSame([], $this->homeAssistant()->serviceCalls());
        $this->timers()->advance(60);
        self::assertSame(21.0, $this->homeAssistant()->serviceCalls()[0]->serviceData['temperature']);
    }

    private function roomStatus(): \App\Application\Engine\Evaluation\RoomStatus
    {
        foreach ($this->service(\App\Application\Engine\Evaluation\LiveStatusPublisher::class)->snapshot() as $status) {
            if ($status->roomId->equals($this->roomId)) {
                return $status;
            }
        }

        self::fail('The room has no status.');
    }

    /**
     * @return list<RoomOverride>
     */
    private function overrides(): array
    {
        $this->em()->clear();

        return $this->service(RoomOverrideRepository::class)->findAll();
    }
}
