<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Engine\Evaluation\LiveStatusPublisher;
use App\Application\Engine\Lifecycle\Engine;
use App\Domain\Climate\Season;
use App\Infrastructure\Persistence\Entity\CommandLog;
use App\Infrastructure\Persistence\Entity\Timer;
use App\Infrastructure\Persistence\Repository\CommandLogRepository;
use App\Infrastructure\Persistence\Repository\TimerRepository;
use App\Tests\Double\ImmediateShutdown;
use App\Tests\Double\RecordingControlServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;

#[CoversClass(Engine::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure')]
final class EngineStartupTest extends EngineTestCase
{
    public function testStartupWithTheDeviceAlreadyAtTargetSendsNothing(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();

        $this->startEngine();

        self::assertSame([], $this->homeAssistant()->serviceCalls());
        $status = $this->service(LiveStatusPublisher::class)->snapshot();
        self::assertCount(1, $status);
        self::assertSame(21.0, $status[0]->decision->target?->toFloat());
        self::assertSame(19.5, $status[0]->currentTemperature?->toFloat());
        self::assertSame(1, $this->service(RecordingControlServer::class)->starts);
        self::assertSame(1, $this->homeAssistant()->subscriptionCount());
    }

    public function testStartupSetsTheScheduledTargetOnADeviceThatDiffers(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'off');
        $this->seedSensor();

        $this->startEngine();

        $calls = $this->homeAssistant()->serviceCalls();
        self::assertCount(1, $calls);
        self::assertSame('climate', $calls[0]->domain);
        self::assertSame('set_temperature', $calls[0]->service);
        self::assertSame(['temperature' => 21.0, 'hvac_mode' => 'heat'], $calls[0]->serviceData);
        self::assertSame(['entity_id' => self::DEVICE], $calls[0]->target);

        $log = $this->service(CommandLogRepository::class)->findOneByContextId($calls[0]->context->id);
        self::assertInstanceOf(CommandLog::class, $log);
        self::assertSame(self::DEVICE, $log->getDeviceEntityId());
        self::assertSame('climate.set_temperature', $log->getService());
        self::assertSame(21.0, $log->getTargetTemperature());
    }

    public function testStartupWritesOneEngineStartedAuditEntryAndNoDecisionChange(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();

        $this->startEngine();

        $started = $this->auditEntries('audit.engine.started');
        self::assertCount(1, $started);
        self::assertSame(['timers' => 0, 'overrides' => 0, 'commands' => 0, 'rooms' => 1], $started[0]->getParams());
        self::assertSame([], $this->auditEntries('audit.decision.changed'));
    }

    public function testRestartRestoresTimersAndSendsNothing(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'off');
        $this->seedSensor();
        $this->startEngine();
        $first = $this->homeAssistant()->serviceCalls()[0];
        $this->deviceAppliesCommand($first);
        $boundary = $this->service(TimerRepository::class)->findOneByKey('room.boundary.'.$this->service(LiveStatusPublisher::class)->snapshot()[0]->roomId);
        self::assertInstanceOf(Timer::class, $boundary);
        $this->engine()->stop();

        // The add-on restarts five minutes later; Home Assistant kept the device as it was left.
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->clock()->sleep(300);
        $this->homeAssistant()->withConfig(new \App\Application\Port\HomeAssistant\HomeAssistantConfig('fr', 'Europe/Paris', '°C', '2026.10.0'));
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();

        $this->startEngine();

        self::assertSame([], $this->homeAssistant()->serviceCalls());
        self::assertSame(1, $this->timers()->pendingCount() - $this->maintenanceTimers());
        $started = $this->auditEntries('audit.engine.started');
        self::assertCount(2, $started);
        self::assertContains(1, array_map(static fn ($entry): mixed => $entry->getParams()['commands'] ?? null, $started));
    }

    public function testATimerThatCameDueWhileTheEngineWasDownFiresOnceAtRestart(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'off');
        $this->seedSensor();
        $this->startEngine();
        $this->deviceAppliesCommand($this->homeAssistant()->serviceCalls()[0]);
        $this->engine()->stop();

        // Back at 23:00 Paris time: the 22:30 night slot started while the engine was down.
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->clock()->sleep(15 * 3600);
        $this->homeAssistant()->withConfig(new \App\Application\Port\HomeAssistant\HomeAssistantConfig('fr', 'Europe/Paris', '°C', '2026.10.0'));
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();

        $this->startEngine();

        $calls = $this->homeAssistant()->serviceCalls();
        self::assertCount(1, $calls);
        self::assertSame(17.0, $calls[0]->serviceData['temperature']);
    }

    public function testRunStartsWaitsForTheShutdownSignalAndStopsGracefully(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();

        $this->engine()->run();

        self::assertSame(1, $this->service(ImmediateShutdown::class)->waits);
        self::assertSame(1, $this->service(RecordingControlServer::class)->stops);
        self::assertFalse($this->homeAssistant()->isConnected());
        self::assertSame(0, $this->homeAssistant()->subscriptionCount());
    }

    public function testStopWithoutStartDoesNothing(): void
    {
        $this->engine()->stop();

        self::assertSame(0, $this->service(RecordingControlServer::class)->stops);
    }

    public function testStopKeepsThePersistedTimers(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->startEngine();
        $persisted = \count($this->service(TimerRepository::class)->findAllPending());

        $this->engine()->stop();

        self::assertGreaterThanOrEqual(2, $persisted);
        self::assertSame(0, $this->timers()->pendingCount());
        self::assertCount($persisted, $this->service(TimerRepository::class)->findAllPending());
    }

    private function maintenanceTimers(): int
    {
        return null === $this->service(TimerRepository::class)->findOneByKey('daily_maintenance') ? 0 : 1;
    }
}
