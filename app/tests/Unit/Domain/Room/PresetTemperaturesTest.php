<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Room;

use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Temperature;
use App\Domain\Exception\InvalidValueException;
use App\Domain\Exception\PresetTemperatureNotFoundException;
use App\Domain\Preset\PresetKey;
use App\Domain\Room\PresetTemperatures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PresetTemperatures::class)]
#[CoversClass(PresetTemperatureNotFoundException::class)]
#[UsesClass(Temperature::class)]
#[UsesClass(PresetKey::class)]
final class PresetTemperaturesTest extends TestCase
{
    public function testLooksUpPerPresetAndMode(): void
    {
        $eco = new PresetKey('eco');
        $temperatures = PresetTemperatures::empty()
            ->with($eco, HvacMode::Heat, new Temperature(18.0))
            ->with($eco, HvacMode::Cool, new Temperature(27.0));

        self::assertSame(18.0, $temperatures->for($eco, HvacMode::Heat)->toFloat());
        self::assertSame(27.0, $temperatures->for($eco, HvacMode::Cool)->toFloat());
        self::assertTrue($temperatures->has($eco, HvacMode::Heat));
    }

    public function testWithReturnsACopy(): void
    {
        $eco = new PresetKey('eco');
        $original = PresetTemperatures::empty()->with($eco, HvacMode::Heat, new Temperature(18.0));
        $changed = $original->with($eco, HvacMode::Heat, new Temperature(19.0));

        self::assertSame(18.0, $original->for($eco, HvacMode::Heat)->toFloat());
        self::assertSame(19.0, $changed->for($eco, HvacMode::Heat)->toFloat());
    }

    public function testMissingTemperatureThrows(): void
    {
        $eco = new PresetKey('eco');
        $temperatures = PresetTemperatures::empty()->with($eco, HvacMode::Heat, new Temperature(18.0));

        self::assertFalse($temperatures->has($eco, HvacMode::Cool));

        $this->expectException(PresetTemperatureNotFoundException::class);
        $this->expectExceptionMessage('preset "eco" in mode "cool"');

        $temperatures->for($eco, HvacMode::Cool);
    }

    public function testOffModeIsRejected(): void
    {
        $this->expectException(InvalidValueException::class);

        PresetTemperatures::empty()->with(new PresetKey('eco'), HvacMode::Off, new Temperature(18.0));
    }
}
