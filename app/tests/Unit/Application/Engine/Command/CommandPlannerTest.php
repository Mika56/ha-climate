<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Engine\Command;

use App\Application\Engine\Command\CommandPlanner;
use App\Application\Engine\Command\PlannedCommand;
use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\StateContext;
use App\Domain\Climate\HvacMode;
use App\Domain\Decision\Decision;
use App\Domain\Decision\Reason;
use App\Domain\Device\AntiCyclingPolicy;
use App\Domain\Device\Device;
use App\Domain\Device\DeviceRole;
use App\Tests\Unit\Domain\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommandPlanner::class)]
#[UsesClass(PlannedCommand::class)]
#[UsesClass(EntityState::class)]
#[UsesClass(StateContext::class)]
#[UsesNamespace('App\Domain')]
final class CommandPlannerTest extends TestCase
{
    /**
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('heatingCases')]
    public function testPlansHeatingOnlyWhenTheDeviceDiffers(string $state, array $attributes, bool $sends): void
    {
        $decision = Decision::heat(Fixtures::temperature(21.0), new Reason('reason.schedule'));

        $command = (new CommandPlanner())->plan(Fixtures::room(), Fixtures::device(DeviceRole::Heating), $decision, self::state($state, $attributes));

        self::assertSame($sends, null !== $command);
        if (null !== $command) {
            self::assertSame(['temperature' => 21.0, 'hvac_mode' => 'heat'], $command->serviceData());
            self::assertSame('set_temperature', $command->service());
        }
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, bool}>
     */
    public static function heatingCases(): iterable
    {
        yield 'already at target' => ['heat', ['temperature' => 21.0], false];
        yield 'target within rounding' => ['heat', ['temperature' => 21.04], false];
        yield 'target as string' => ['heat', ['temperature' => '21.0'], false];
        yield 'other target' => ['heat', ['temperature' => 19.0], true];
        yield 'no target reported' => ['heat', [], true];
        yield 'device off' => ['off', ['temperature' => 21.0], true];
        yield 'other mode' => ['heat_cool', ['temperature' => 21.0], true];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('hysteresisCases')]
    public function testASetpointCloserThanTheHysteresisIsSkippedWhileTheModeIsUnchanged(string $state, array $attributes, float $hysteresis, bool $sends): void
    {
        $device = new Device(Fixtures::entity(), DeviceRole::Heating, new AntiCyclingPolicy(0, 0, $hysteresis));
        $decision = Decision::heat(Fixtures::temperature(21.0), new Reason('reason.schedule'));

        $command = (new CommandPlanner())->plan(Fixtures::room(), $device, $decision, self::state($state, $attributes));

        self::assertSame($sends, null !== $command);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, float, bool}>
     */
    public static function hysteresisCases(): iterable
    {
        yield 'smaller than the hysteresis' => ['heat', ['temperature' => 21.4], 0.5, false];
        yield 'exactly the hysteresis' => ['heat', ['temperature' => 21.5], 0.5, true];
        yield 'below, going down' => ['heat', ['temperature' => 20.6], 0.5, false];
        yield 'larger than the hysteresis' => ['heat', ['temperature' => 22.0], 0.5, true];
        yield 'no hysteresis' => ['heat', ['temperature' => 21.1], 0.0, true];
        yield 'mode change is never skipped' => ['cool', ['temperature' => 21.2], 0.5, true];
        yield 'turning on is never skipped' => ['off', ['temperature' => 21.2], 2.0, true];
    }

    public function testAnOffDecisionTurnsARunningDeviceOff(): void
    {
        $command = (new CommandPlanner())->plan(Fixtures::room(), Fixtures::device(), Decision::off(new Reason('reason.no_rule')), self::state('heat', ['temperature' => 21.0]));

        self::assertSame('set_hvac_mode', $command?->service());
        self::assertSame(['hvac_mode' => 'off'], $command->serviceData());
    }

    public function testNothingToDoForADeviceAlreadyOff(): void
    {
        self::assertNull((new CommandPlanner())->plan(Fixtures::room(), Fixtures::device(), Decision::off(new Reason('reason.no_rule')), self::state('off')));
    }

    public function testADeviceThatCannotOperateInTheModeIsWantedOff(): void
    {
        $decision = Decision::heat(Fixtures::temperature(21.0), new Reason('reason.schedule'));
        $coolingOnly = Fixtures::device(DeviceRole::Cooling);

        $running = (new CommandPlanner())->plan(Fixtures::room(), $coolingOnly, $decision, self::state('cool', ['temperature' => 24.0]));
        $stopped = (new CommandPlanner())->plan(Fixtures::room(), $coolingOnly, $decision, self::state('off'));

        self::assertSame(HvacMode::Off, $running?->mode);
        self::assertNull($stopped);
    }

    public function testCoolingUsesTheCoolMode(): void
    {
        $decision = Decision::cool(Fixtures::temperature(24.0), new Reason('reason.schedule'));

        $command = (new CommandPlanner())->plan(Fixtures::room(), Fixtures::device(DeviceRole::Reversible), $decision, self::state('heat', ['temperature' => 21.0]));

        self::assertSame(['temperature' => 24.0, 'hvac_mode' => 'cool'], $command?->serviceData());
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function state(string $state, array $attributes = []): EntityState
    {
        return new EntityState('climate.living', $state, $attributes, new \DateTimeImmutable(), new \DateTimeImmutable(), new StateContext('c'));
    }
}
