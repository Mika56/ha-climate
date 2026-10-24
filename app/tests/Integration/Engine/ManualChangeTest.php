<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Engine\Command\ManualChangeAuditSubscriber;
use App\Application\Engine\Command\ManualChangeDetector;
use App\Application\Engine\Command\ManualDeviceChangeDetected;
use App\Application\Engine\State\ChangeProcessor;
use App\Application\Port\HomeAssistant\HomeAssistantConfig;
use App\Application\Port\HomeAssistant\StateContext;
use App\Domain\Climate\Season;
use App\Infrastructure\Persistence\Enum\ActorType;
use App\Infrastructure\Persistence\Repository\CommandLogRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[CoversClass(ManualChangeDetector::class)]
#[CoversClass(ManualChangeAuditSubscriber::class)]
#[CoversClass(ManualDeviceChangeDetected::class)]
#[CoversClass(ChangeProcessor::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure')]
final class ManualChangeTest extends EngineTestCase
{
    /** @var list<ManualDeviceChangeDetected> */
    private array $events = [];

    private string $roomId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = [];
        $this->service(EventDispatcherInterface::class)->addListener(ManualDeviceChangeDetected::class, function (ManualDeviceChangeDetected $event): void {
            $this->events[] = $event;
        });
        $this->setSeason(Season::Winter);
        $this->roomId = $this->createRoom()->getId()->toRfc4122();
        $this->seedDevice(mode: 'off');
        $this->seedSensor();
        $this->startEngine();
        $this->deviceAppliesCommand($this->homeAssistant()->serviceCalls()[0]);
        $this->homeAssistant()->clearServiceCalls();
    }

    public function testTheDeviceFollowingOurOwnCommandIsNotAManualChange(): void
    {
        self::assertSame([], $this->events);
        self::assertSame([], $this->auditEntries('audit.manual_change.detected'));
    }

    public function testAChangeMadeByAUserIsDetectedAndAudited(): void
    {
        $this->homeAssistant()->pushStateChange(self::DEVICE, 'heat', ['temperature' => 24.0, 'current_temperature' => 19.5], new StateContext('ui-1', null, 'user-42'));

        self::assertCount(1, $this->events);
        $event = $this->events[0];
        self::assertSame($this->roomId, (string) $event->roomId);
        self::assertSame(self::DEVICE, (string) $event->device);
        self::assertSame('heat', $event->oldMode);
        self::assertSame(21.0, $event->oldTemperature);
        self::assertSame('heat', $event->newMode);
        self::assertSame(24.0, $event->newTemperature);
        self::assertSame('user-42', $event->userId);

        $audit = $this->auditEntries('audit.manual_change.detected');
        self::assertCount(1, $audit);
        self::assertSame(ActorType::User, $audit[0]->getActorType());
        self::assertSame('user-42', $audit[0]->getActorId());
        self::assertSame([
            'device' => self::DEVICE,
            'oldMode' => 'heat',
            'newMode' => 'heat',
            'oldTemperature' => '21 °C',
            'newTemperature' => '24 °C',
        ], $audit[0]->getParams());
    }

    public function testAChangeMadeOnTheDeviceItselfHasNoUser(): void
    {
        $this->homeAssistant()->pushStateChange(self::DEVICE, 'off', ['temperature' => 21.0], new StateContext('physical-1'));

        self::assertCount(1, $this->events);
        self::assertNull($this->events[0]->userId);
        self::assertSame('off', $this->events[0]->newMode);
        $audit = $this->auditEntries('audit.manual_change.detected');
        self::assertSame(ActorType::Device, $audit[0]->getActorType());
        self::assertSame(self::DEVICE, $audit[0]->getActorId());
    }

    public function testAttributeNoiseIsNotAManualChange(): void
    {
        $this->homeAssistant()->pushStateChange(self::DEVICE, 'heat', ['temperature' => 21.0, 'current_temperature' => 20.1, 'hvac_action' => 'heating'], new StateContext('device-report-1'));
        $this->homeAssistant()->pushStateChange(self::DEVICE, 'heat', ['temperature' => 21.04, 'current_temperature' => 20.3], new StateContext('device-report-2'));

        self::assertSame([], $this->events);
    }

    public function testTheObservedStateBecomesTheReferenceSoTheChangeIsReportedOnce(): void
    {
        $this->homeAssistant()->pushStateChange(self::DEVICE, 'heat', ['temperature' => 24.0], new StateContext('ui-1', null, 'user-42'));
        $this->homeAssistant()->pushStateChange(self::DEVICE, 'heat', ['temperature' => 24.0, 'current_temperature' => 20.5], new StateContext('device-report-1'));

        self::assertCount(1, $this->events);
    }

    public function testAnEchoOfOurOwnCommandWithAnUnknownContextIsNotManual(): void
    {
        // The night slot sends 17 degrees. The device's report may reach us before the answer to the service
        // call, hence with a context we do not know yet: its values are those of the order, so it is ours.
        $this->timers()->advance(14.5 * 3600);
        self::assertSame(17.0, $this->homeAssistant()->serviceCalls()[0]->serviceData['temperature']);

        $this->homeAssistant()->pushStateChange(self::DEVICE, 'heat', ['temperature' => 17.0], new StateContext('unregistered-1'));

        self::assertSame([], $this->events);
    }

    public function testChangesOfAnUnavailableOrUnrelatedEntityAreIgnored(): void
    {
        $this->homeAssistant()->pushStateChange(self::DEVICE, 'unavailable', [], new StateContext('gone-1'));
        $this->homeAssistant()->pushStateChange(self::SENSOR, '18.0', null, new StateContext('sensor-1'));

        self::assertSame([], $this->events);
    }

    public function testAManualChangeIsDetectedAgainstTheCommandLogAfterARestart(): void
    {
        $this->engine()->stop();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->events = [];
        $this->service(EventDispatcherInterface::class)->addListener(ManualDeviceChangeDetected::class, function (ManualDeviceChangeDetected $event): void {
            $this->events[] = $event;
        });
        $this->homeAssistant()->withConfig(new HomeAssistantConfig('fr', 'Europe/Paris', '°C', '2026.10.0'));
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->startEngine();

        // After a restart the log says "heat 21" and the device still is: a change to 25 is manual.
        $this->homeAssistant()->pushStateChange(self::DEVICE, 'heat', ['temperature' => 25.0], new StateContext('ui-9', null, 'user-1'));

        self::assertCount(1, $this->events);
        self::assertSame(21.0, $this->events[0]->oldTemperature);
        self::assertSame(25.0, $this->events[0]->newTemperature);
    }

    public function testTheContextOfOurCommandIsStillRecognisedAfterARestart(): void
    {
        $this->engine()->stop();
        $command = $this->service(CommandLogRepository::class)->findAll()[0];
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->events = [];
        $this->service(EventDispatcherInterface::class)->addListener(ManualDeviceChangeDetected::class, function (ManualDeviceChangeDetected $event): void {
            $this->events[] = $event;
        });
        $this->homeAssistant()->withConfig(new HomeAssistantConfig('fr', 'Europe/Paris', '°C', '2026.10.0'));
        $this->seedDevice(mode: 'off');
        $this->seedSensor();
        $this->startEngine();

        // The first command of the new run is not known in memory; the one of the previous run is in the log.
        $this->homeAssistant()->pushStateChange(self::DEVICE, 'heat', ['temperature' => 19.0], new StateContext($command->getHaContextId()));

        self::assertSame([], $this->events);
    }
}
