<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Decision;

use App\Domain\Climate\Temperature;
use App\Domain\Climate\TemperatureDelta;
use App\Domain\Decision\ActiveOverride;
use App\Domain\Decision\OverrideType;
use App\Domain\Exception\InvalidValueException;
use App\Tests\Unit\Domain\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActiveOverride::class)]
#[UsesClass(Temperature::class)]
#[UsesClass(TemperatureDelta::class)]
final class ActiveOverrideTest extends TestCase
{
    public function testBoost(): void
    {
        $endsAt = Fixtures::at('2026-10-26 09:00');
        $boost = ActiveOverride::boost(new TemperatureDelta(2.0), $endsAt);

        self::assertSame(OverrideType::Boost, $boost->type);
        self::assertSame(2.0, $boost->delta?->toFloat());
        self::assertNull($boost->target);
        self::assertSame($endsAt, $boost->endsAt);
        self::assertFalse($boost->untilNextSlot);
    }

    public function testManualUntilNextSlot(): void
    {
        $manual = ActiveOverride::manual(new Temperature(22.0), null, true);

        self::assertSame(OverrideType::Manual, $manual->type);
        self::assertSame(22.0, $manual->target?->toFloat());
        self::assertNull($manual->delta);
        self::assertNull($manual->endsAt);
        self::assertTrue($manual->untilNextSlot);
    }

    public function testBoostWithoutDeltaIsRejected(): void
    {
        $this->expectException(InvalidValueException::class);

        new ActiveOverride(OverrideType::Boost, null, null, null, false);
    }

    public function testBoostWithTargetIsRejected(): void
    {
        $this->expectException(InvalidValueException::class);

        new ActiveOverride(OverrideType::Boost, new TemperatureDelta(1.0), new Temperature(20.0), null, false);
    }

    public function testManualWithoutTargetIsRejected(): void
    {
        $this->expectException(InvalidValueException::class);

        new ActiveOverride(OverrideType::Manual, null, null, null, false);
    }

    public function testManualWithDeltaIsRejected(): void
    {
        $this->expectException(InvalidValueException::class);

        new ActiveOverride(OverrideType::Manual, new TemperatureDelta(1.0), new Temperature(20.0), null, false);
    }
}
