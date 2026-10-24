<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Decision;

use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Temperature;
use App\Domain\Decision\Decision;
use App\Domain\Decision\Reason;
use App\Domain\Exception\InvalidValueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Decision::class)]
#[CoversClass(Reason::class)]
#[UsesClass(Temperature::class)]
final class DecisionTest extends TestCase
{
    public function testOff(): void
    {
        $decision = Decision::off(new Reason('reason.season_off'));

        self::assertSame(HvacMode::Off, $decision->mode);
        self::assertNull($decision->target);
        self::assertTrue($decision->isOff());
        self::assertSame('reason.season_off', $decision->reason->code);
    }

    public function testHeat(): void
    {
        $decision = Decision::heat(new Temperature(21.0), new Reason('reason.schedule', ['preset' => 'comfort', 'until' => '08:30']));

        self::assertSame(HvacMode::Heat, $decision->mode);
        self::assertSame(21.0, $decision->target?->toFloat());
        self::assertFalse($decision->isOff());
        self::assertSame(['preset' => 'comfort', 'until' => '08:30'], $decision->reason->params);
    }

    public function testCool(): void
    {
        $decision = Decision::cool(new Temperature(24.0), new Reason('reason.schedule'));

        self::assertSame(HvacMode::Cool, $decision->mode);
        self::assertSame(24.0, $decision->target?->toFloat());
        self::assertSame([], $decision->reason->params);
    }

    public function testOffWithTargetIsRejected(): void
    {
        $this->expectException(InvalidValueException::class);

        new Decision(HvacMode::Off, new Temperature(20.0), new Reason('reason.x'));
    }

    public function testHeatWithoutTargetIsRejected(): void
    {
        $this->expectException(InvalidValueException::class);

        new Decision(HvacMode::Heat, null, new Reason('reason.x'));
    }

    public function testCoolWithoutTargetIsRejected(): void
    {
        $this->expectException(InvalidValueException::class);

        new Decision(HvacMode::Cool, null, new Reason('reason.x'));
    }

    public function testReasonCodeMustNotBeBlank(): void
    {
        $this->expectException(InvalidValueException::class);

        new Reason(' ');
    }
}
