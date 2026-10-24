<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Climate;

use App\Domain\Climate\HvacMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HvacMode::class)]
final class HvacModeTest extends TestCase
{
    #[DataProvider('provideHomeAssistantValues')]
    public function testBackingValuesMatchHomeAssistant(string $value, ?HvacMode $expected): void
    {
        self::assertSame($expected, HvacMode::tryFrom($value));
    }

    /**
     * @return iterable<string, array{string, ?HvacMode}>
     */
    public static function provideHomeAssistantValues(): iterable
    {
        yield 'heat' => ['heat', HvacMode::Heat];
        yield 'cool' => ['cool', HvacMode::Cool];
        yield 'off' => ['off', HvacMode::Off];
        yield 'unsupported' => ['auto', null];
    }
}
