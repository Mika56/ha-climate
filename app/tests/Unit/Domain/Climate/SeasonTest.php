<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Climate;

use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Season::class)]
final class SeasonTest extends TestCase
{
    #[DataProvider('provideSeasons')]
    public function testActiveHvacMode(Season $season, HvacMode $expected): void
    {
        self::assertSame($expected, $season->activeHvacMode());
    }

    /**
     * @return iterable<string, array{Season, HvacMode}>
     */
    public static function provideSeasons(): iterable
    {
        yield 'winter heats' => [Season::Winter, HvacMode::Heat];
        yield 'summer cools' => [Season::Summer, HvacMode::Cool];
    }
}
