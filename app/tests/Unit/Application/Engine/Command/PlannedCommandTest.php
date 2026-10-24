<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Engine\Command;

use App\Application\Engine\Command\GuardVerdict;
use App\Application\Engine\Command\PlannedCommand;
use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\StateContext;
use App\Domain\Climate\HvacMode;
use App\Domain\Decision\Reason;
use App\Domain\Exception\InvalidValueException;
use App\Domain\Room\RoomId;
use App\Tests\Unit\Domain\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlannedCommand::class)]
#[CoversClass(GuardVerdict::class)]
#[UsesClass(EntityState::class)]
#[UsesClass(StateContext::class)]
#[UsesNamespace('App\Domain')]
final class PlannedCommandTest extends TestCase
{
    public function testATurnOnCommandSetsTheTargetAndTheMode(): void
    {
        $command = PlannedCommand::setTarget(new RoomId('living'), Fixtures::device(), HvacMode::Heat, Fixtures::temperature(21.0), self::state('off'));

        self::assertSame('set_temperature', $command->service());
        self::assertSame(['temperature' => 21.0, 'hvac_mode' => 'heat'], $command->serviceData());
        self::assertTrue($command->isTurningOn());
        self::assertFalse($command->isTurningOff());
    }

    public function testATurnOffCommandOnlySetsTheMode(): void
    {
        $command = PlannedCommand::turnOff(new RoomId('living'), Fixtures::device(), self::state('heat'));

        self::assertSame('set_hvac_mode', $command->service());
        self::assertSame(['hvac_mode' => 'off'], $command->serviceData());
        self::assertTrue($command->isTurningOff());
        self::assertFalse($command->isTurningOn());
    }

    public function testChangingTheTargetOfARunningDeviceIsNeitherOnNorOff(): void
    {
        $command = PlannedCommand::setTarget(new RoomId('living'), Fixtures::device(), HvacMode::Heat, Fixtures::temperature(19.0), self::state('heat'));

        self::assertFalse($command->isTurningOn());
        self::assertFalse($command->isTurningOff());
    }

    public function testATurnOnCommandRequiresATarget(): void
    {
        $this->expectException(InvalidValueException::class);

        PlannedCommand::setTarget(new RoomId('living'), Fixtures::device(), HvacMode::Off, Fixtures::temperature(21.0), self::state('off'));
    }

    public function testVerdicts(): void
    {
        $until = new \DateTimeImmutable('2026-10-26 08:00:00');

        $allow = GuardVerdict::allow();
        $defer = GuardVerdict::deferUntil($until, new Reason('reason.min_off'));

        self::assertFalse($allow->isDeferred());
        self::assertNull($allow->deferredUntil);
        self::assertTrue($defer->isDeferred());
        self::assertSame($until, $defer->deferredUntil);
        self::assertSame('reason.min_off', $defer->reason?->code);
    }

    private static function state(string $state): EntityState
    {
        return new EntityState('climate.living', $state, [], new \DateTimeImmutable(), new \DateTimeImmutable(), new StateContext('c'));
    }
}
