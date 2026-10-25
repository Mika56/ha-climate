<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Engine\Audit\AuditLogger;
use App\Application\Engine\Command\CommandExecutor;
use App\Application\Engine\Command\CommandPlanner;
use App\Application\Engine\Command\OwnCommandRegistry;
use App\Application\Engine\EngineSnapshotFactory;
use App\Application\Engine\State\StateStore;
use App\Application\Engine\Timer\TimerScheduler;
use App\Application\Port\HomeAssistant\CommandFailedException;
use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Domain\Decision\Decision;
use App\Domain\Decision\Reason;
use App\Domain\Room\RoomId;
use App\Infrastructure\Persistence\Entity\CommandLog;
use App\Infrastructure\Persistence\Entity\Timer;
use App\Infrastructure\Persistence\Repository\CommandLogRepository;
use App\Infrastructure\Persistence\Repository\TimerRepository;
use App\Tests\Double\ConfigurableCommandGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[CoversClass(CommandExecutor::class)]
#[CoversClass(OwnCommandRegistry::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure')]
final class CommandExecutorTest extends EngineTestCase
{
    public function testNothingIsSentWhenTheDevicesAreAlreadyAsWanted(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->startEngine();

        $this->homeAssistant()->pushStateChange(self::SENSOR, '19.0');
        $this->homeAssistant()->pushStateChange(self::DEVICE, 'heat', ['temperature' => 21.0, 'current_temperature' => 19.0, 'hvac_action' => 'heating']);

        self::assertSame([], $this->homeAssistant()->serviceCalls());
    }

    public function testADeviceWithoutUsableStateReceivesNothingUntilItComesBack(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'unavailable');
        $this->startEngine();
        self::assertSame([], $this->climateCalls());

        $this->homeAssistant()->pushStateChange(self::DEVICE, 'unknown');
        self::assertSame([], $this->climateCalls());

        $this->homeAssistant()->pushStateChange(self::DEVICE, 'off', ['temperature' => 7.0]);
        self::assertCount(1, $this->climateCalls());
    }

    /**
     * @return list<\App\Tests\Double\RecordedServiceCall> what was sent to climate devices (an unavailable device also raises a notification)
     *
     * @phpstan-impure
     */
    private function climateCalls(): array
    {
        return [...$this->homeAssistant()->serviceCallsTo('climate', 'set_temperature'), ...$this->homeAssistant()->serviceCallsTo('climate', 'set_hvac_mode')];
    }

    public function testADeviceMissingFromHomeAssistantIsSkipped(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();

        $this->startEngine();

        self::assertSame([], $this->homeAssistant()->serviceCalls());
    }

    public function testEveryCommandIsLoggedWithItsContextAndRecognisedAsOurs(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'off');
        $this->startEngine();
        $call = $this->homeAssistant()->serviceCalls()[0];

        $log = $this->service(CommandLogRepository::class)->findOneByContextId($call->context->id);

        self::assertInstanceOf(CommandLog::class, $log);
        self::assertSame(['temperature' => 21.0, 'hvac_mode' => 'heat'], $log->getData());
        self::assertSame(HvacMode::Heat, $log->getHvacMode());
        self::assertTrue($this->service(OwnCommandRegistry::class)->isOwn($call->context->id));
        self::assertFalse($this->service(OwnCommandRegistry::class)->isOwn('someone-else'));
        $sent = $this->auditEntries('audit.command.sent');
        self::assertCount(1, $sent);
        self::assertSame(['device' => self::DEVICE, 'service' => 'climate.set_temperature', 'mode' => 'heat', 'target' => 21.0], $sent[0]->getParams());
    }

    public function testACommandOnItsWayIsNotRepeatedUntilItHadTimeToTakeEffect(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'off');
        $this->seedSensor();
        $this->startEngine();
        self::assertCount(1, $this->homeAssistant()->serviceCalls());

        // The device has not reported its new state yet: other events must not make us send the same order again.
        $this->homeAssistant()->pushStateChange(self::SENSOR, '19.1');
        $this->homeAssistant()->pushStateChange(self::SENSOR, '19.2');
        self::assertCount(1, $this->homeAssistant()->serviceCalls());

        // Still not applied twenty seconds later: the order was lost, send it again.
        $this->clock()->sleep(20);
        $this->homeAssistant()->pushStateChange(self::SENSOR, '19.3');
        self::assertCount(2, $this->homeAssistant()->serviceCalls());
    }

    public function testAFailedCommandIsAuditedAndRetriedAtTheNextEvaluation(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'off');
        $this->seedSensor();
        $this->homeAssistant()->connect();
        $this->homeAssistant()->failNextServiceCall(new CommandFailedException('service_validation_error', 'Temperature out of range'));

        $this->startEngine();

        self::assertSame([], $this->homeAssistant()->serviceCalls());
        self::assertSame([], $this->service(CommandLogRepository::class)->findAll());
        $failed = $this->auditEntries('audit.command.failed');
        self::assertCount(1, $failed);
        self::assertStringContainsString('Temperature out of range', self::auditString($failed[0], 'error'));

        $this->homeAssistant()->pushStateChange(self::SENSOR, '19.0');
        self::assertCount(1, $this->homeAssistant()->serviceCalls(), 'A failed command does not count as sent.');
    }

    public function testAGuardPostponesTheCommandAndTheRoomIsReevaluatedWhenItEnds(): void
    {
        $this->setSeason(Season::Winter);
        $room = $this->createRoom();
        $this->seedDevice(mode: 'off');
        $this->seedSensor();
        $guard = $this->service(ConfigurableCommandGuard::class);
        $guard->deferUntil = $this->clock()->now()->modify('+10 minutes');

        $this->startEngine();

        self::assertSame([], $this->homeAssistant()->serviceCalls());
        $timer = $this->service(TimerRepository::class)->findOneByKey('command.deferred.'.self::DEVICE);
        self::assertInstanceOf(Timer::class, $timer);
        self::assertSame($guard->deferUntil->getTimestamp(), $timer->getDueAt()->getTimestamp());
        self::assertSame($room->getId()->toRfc4122(), $timer->getRoomId()?->toRfc4122());
        $deferred = $this->auditEntries('audit.command.deferred');
        self::assertCount(1, $deferred);
        self::assertSame('reason.test_guard', $deferred[0]->getParams()['reason']);
        self::assertSame(['code' => 'reason.test_guard', 'params' => ['until' => '07:10']], $deferred[0]->getPayload()['reason']);

        // Another evaluation while still deferred: same postponement, audited once.
        $this->homeAssistant()->pushStateChange(self::SENSOR, '19.0');
        self::assertCount(1, $this->auditEntries('audit.command.deferred'));
        self::assertSame([], $this->homeAssistant()->serviceCalls());

        // The guard lets the command through from then on: the timer applies it, nothing else needed.
        $guard->deferUntil = null;
        $this->timers()->advance(10 * 60);

        self::assertCount(1, $this->homeAssistant()->serviceCalls());
        self::assertSame(['temperature' => 21.0, 'hvac_mode' => 'heat'], $this->homeAssistant()->serviceCalls()[0]->serviceData);
        self::assertNull($this->service(TimerRepository::class)->findOneByKey('command.deferred.'.self::DEVICE));
    }

    public function testADeferralIsForgottenWhenTheDeviceReachesTheWantedStateByItself(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'off');
        $this->seedSensor();
        $guard = $this->service(ConfigurableCommandGuard::class);
        $guard->deferUntil = $this->clock()->now()->modify('+10 minutes');
        $this->startEngine();
        self::assertInstanceOf(Timer::class, $this->service(TimerRepository::class)->findOneByKey('command.deferred.'.self::DEVICE));

        $this->homeAssistant()->pushStateChange(self::DEVICE, 'heat', ['temperature' => 21.0]);

        self::assertNull($this->service(TimerRepository::class)->findOneByKey('command.deferred.'.self::DEVICE));
        self::assertSame([], $this->homeAssistant()->serviceCalls());
    }

    public function testWhenSeveralGuardsDeferTheLatestInstantWins(): void
    {
        $this->setSeason(Season::Winter);
        $room = $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->homeAssistant()->connect();
        $this->service(StateStore::class)->load($this->homeAssistant()->getStates());
        $early = new ConfigurableCommandGuard();
        $early->deferUntil = $this->clock()->now()->modify('+5 minutes');
        $late = new ConfigurableCommandGuard();
        $late->deferUntil = $this->clock()->now()->modify('+9 minutes');
        $allowing = new ConfigurableCommandGuard();
        $executor = new CommandExecutor(
            $this->homeAssistant(),
            new CommandPlanner(),
            [$early, $late, $allowing],
            $this->service(OwnCommandRegistry::class),
            $this->service(CommandLogRepository::class),
            $this->service(TimerScheduler::class),
            $this->service(AuditLogger::class),
            $this->clock(),
            new NullLogger(),
            $this->service(EventDispatcherInterface::class),
        );
        $snapshot = $this->service(EngineSnapshotFactory::class)->create();
        $configuration = $snapshot->configuration->room(new RoomId($room->getId()->toRfc4122())) ?? throw new \LogicException('Room not loaded.');

        $executor->execute($configuration, Decision::off(new Reason('reason.no_rule')), $snapshot);

        self::assertSame([], $this->homeAssistant()->serviceCalls());
        $timer = $this->service(TimerRepository::class)->findOneByKey('command.deferred.'.self::DEVICE);
        self::assertSame($late->deferUntil->getTimestamp(), $timer?->getDueAt()->getTimestamp());
        self::assertCount(1, $allowing->checked);
        self::assertTrue($allowing->checked[0]->isTurningOff());
    }

    public function testTheRegistryRevertsAnExpectationWhenTheCommandFails(): void
    {
        $registry = $this->service(OwnCommandRegistry::class);

        $previous = $registry->expect('climate.a', HvacMode::Heat, 21.0);
        self::assertNull($previous);
        self::assertSame(21.0, $registry->lastCommanded('climate.a')?->temperature);

        $registry->revert('climate.a', $previous);
        self::assertNull($registry->lastCommanded('climate.a'));

        $registry->expect('climate.a', HvacMode::Heat, 21.0);
        $second = $registry->expect('climate.a', HvacMode::Off, null);
        $registry->revert('climate.a', $second);
        self::assertSame(HvacMode::Heat, $registry->lastCommanded('climate.a')?->mode);

        $registry->adopt('climate.a', HvacMode::Cool, 24.0);
        self::assertFalse($registry->isInFlight('climate.a', HvacMode::Cool, 24.0), 'An adopted state was never sent by us.');
    }

    public function testTheRegistryOnlyRemembersTheMostRecentContextsInMemory(): void
    {
        $registry = $this->service(OwnCommandRegistry::class);

        for ($i = 0; $i < 600; ++$i) {
            $registry->remember('ctx-'.$i);
        }

        self::assertTrue($registry->isOwn('ctx-599'));
        self::assertFalse($registry->isOwn('ctx-0'), 'Evicted from memory and absent from the command log.');
    }

    public function testTheRegistryIsRebuiltFromTheCommandLogAfterARestart(): void
    {
        $this->setSeason(Season::Winter);
        $this->createRoom();
        $this->seedDevice(mode: 'off');
        $this->startEngine();
        $call = $this->homeAssistant()->serviceCalls()[0];
        $this->engine()->stop();

        self::ensureKernelShutdown();
        self::bootKernel();
        $registry = $this->service(OwnCommandRegistry::class);

        self::assertSame(1, $registry->restore());
        self::assertSame(21.0, $registry->lastCommanded(self::DEVICE)?->temperature);
        self::assertTrue($registry->isOwn($call->context->id), 'Found in the command log.');
    }
}
