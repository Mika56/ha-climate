<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Engine\Command;

use App\Application\Engine\Command\AntiCyclingGuard;
use App\Application\Engine\Command\GuardVerdict;
use App\Application\Engine\Command\PlannedCommand;
use App\Application\Engine\Configuration\EngineConfiguration;
use App\Application\Engine\EngineSnapshot;
use App\Application\Engine\State\StateStore;
use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\StateContext;
use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Domain\Decision\GlobalPolicy;
use App\Domain\Device\AntiCyclingPolicy;
use App\Domain\Device\Device;
use App\Domain\Device\DeviceRole;
use App\Tests\Unit\Domain\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(AntiCyclingGuard::class)]
#[UsesClass(GuardVerdict::class)]
#[UsesClass(PlannedCommand::class)]
#[UsesClass(EngineSnapshot::class)]
#[UsesClass(EngineConfiguration::class)]
#[UsesClass(StateStore::class)]
#[UsesClass(EntityState::class)]
#[UsesClass(StateContext::class)]
#[UsesNamespace('App\Domain')]
final class AntiCyclingGuardTest extends TestCase
{
    private const string CHANGED = '2026-10-26 07:00:00';

    public function testTurningOnBeforeTheMinimumOffDurationIsPostponedUntilItElapses(): void
    {
        $verdict = (new AntiCyclingGuard())->check(self::turnOn('off'), self::snapshot('2026-10-26 07:09:59'));

        self::assertTrue($verdict->isDeferred());
        self::assertEquals(Fixtures::at('2026-10-26 07:10:00'), $verdict->deferredUntil);
        self::assertSame(AntiCyclingGuard::REASON_MIN_OFF, $verdict->reason?->code);
        self::assertSame(['device' => 'climate.living', 'seconds' => 600, 'until' => '2026-10-26T07:10:00+01:00'], $verdict->reason->params);
    }

    public function testTurningOnExactlyWhenTheMinimumOffDurationElapsedIsAllowed(): void
    {
        self::assertFalse((new AntiCyclingGuard())->check(self::turnOn('off'), self::snapshot('2026-10-26 07:10:00'))->isDeferred());
        self::assertFalse((new AntiCyclingGuard())->check(self::turnOn('off'), self::snapshot('2026-10-26 09:00:00'))->isDeferred());
    }

    public function testTurningOffBeforeTheMinimumOnDurationIsPostponedUntilItElapses(): void
    {
        $verdict = (new AntiCyclingGuard())->check(self::turnOff('heat'), self::snapshot('2026-10-26 07:04:59'));

        self::assertTrue($verdict->isDeferred());
        self::assertEquals(Fixtures::at('2026-10-26 07:05:00'), $verdict->deferredUntil);
        self::assertSame(AntiCyclingGuard::REASON_MIN_ON, $verdict->reason?->code);
        self::assertSame(300, $verdict->reason->params['seconds']);
    }

    public function testTurningOffExactlyWhenTheMinimumOnDurationElapsedIsAllowed(): void
    {
        self::assertFalse((new AntiCyclingGuard())->check(self::turnOff('heat'), self::snapshot('2026-10-26 07:05:00'))->isDeferred());
    }

    public function testASetpointChangeOfARunningDeviceIsNeverPostponed(): void
    {
        $command = PlannedCommand::setTarget(Fixtures::room()->id, self::device(), HvacMode::Heat, Fixtures::temperature(22.0), self::state('heat'));

        self::assertFalse((new AntiCyclingGuard())->check($command, self::snapshot('2026-10-26 07:00:01'))->isDeferred());
    }

    public function testSwitchingBetweenHeatAndCoolIsNeverPostponed(): void
    {
        $command = PlannedCommand::setTarget(Fixtures::room()->id, self::device(), HvacMode::Cool, Fixtures::temperature(24.0), self::state('heat'));

        self::assertFalse((new AntiCyclingGuard())->check($command, self::snapshot('2026-10-26 07:00:01'))->isDeferred());
    }

    public function testNoMinimumDurationMeansNoDelay(): void
    {
        $device = new Device(Fixtures::entity(), DeviceRole::Heating, new AntiCyclingPolicy(0, 0, 0.5));
        $on = PlannedCommand::setTarget(Fixtures::room()->id, $device, HvacMode::Heat, Fixtures::temperature(21.0), self::state('off'));
        $off = PlannedCommand::turnOff(Fixtures::room()->id, $device, self::state('heat'));

        self::assertFalse((new AntiCyclingGuard())->check($on, self::snapshot(self::CHANGED))->isDeferred());
        self::assertFalse((new AntiCyclingGuard())->check($off, self::snapshot(self::CHANGED))->isDeferred());
    }

    private static function turnOn(string $from): PlannedCommand
    {
        return PlannedCommand::setTarget(Fixtures::room()->id, self::device(), HvacMode::Heat, Fixtures::temperature(21.0), self::state($from));
    }

    private static function turnOff(string $from): PlannedCommand
    {
        return PlannedCommand::turnOff(Fixtures::room()->id, self::device(), self::state($from));
    }

    private static function device(): Device
    {
        return new Device(Fixtures::entity(), DeviceRole::Heating, new AntiCyclingPolicy(300, 600, 0.5));
    }

    private static function state(string $state): EntityState
    {
        $changed = Fixtures::at(self::CHANGED);

        return new EntityState('climate.living', $state, ['temperature' => 21.0], $changed, $changed, new StateContext('c'));
    }

    private static function snapshot(string $now): EngineSnapshot
    {
        return new EngineSnapshot(
            Fixtures::at($now),
            new EngineConfiguration([], [], new GlobalPolicy(1800, Fixtures::preset('eco'), Fixtures::preset('away')), Season::Winter, [], null, null, 90, 120),
            new StateStore(),
        );
    }
}
