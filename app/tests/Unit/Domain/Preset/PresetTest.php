<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Preset;

use App\Domain\Exception\InvalidValueException;
use App\Domain\Preset\Preset;
use App\Domain\Preset\PresetKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Preset::class)]
#[UsesClass(PresetKey::class)]
final class PresetTest extends TestCase
{
    public function testHoldsKeyAndLabel(): void
    {
        $preset = new Preset(new PresetKey('eco'), 'Eco');

        self::assertSame('eco', (string) $preset->key);
        self::assertSame('Eco', $preset->label);
    }

    public function testBlankLabelIsRejected(): void
    {
        $this->expectException(InvalidValueException::class);

        new Preset(new PresetKey('eco'), '  ');
    }
}
