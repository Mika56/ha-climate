<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Engine\Heating;

use App\Application\Engine\Heating\HeatingEpisode;
use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Temperature;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HeatingEpisode::class)]
#[UsesClass(Temperature::class)]
final class HeatingEpisodeTest extends TestCase
{
    #[DataProvider('provideReadings')]
    public function testIsReachedBy(HvacMode $mode, float $start, float $target, float $current, bool $reached): void
    {
        $episode = new HeatingEpisode($mode, new Temperature($target), new Temperature($start), new \DateTimeImmutable('2026-10-26 07:00:00'));

        self::assertSame($reached, $episode->isReachedBy(new Temperature($current)));
    }

    /**
     * @return iterable<string, array{HvacMode, float, float, float, bool}>
     */
    public static function provideReadings(): iterable
    {
        yield 'heat, below' => [HvacMode::Heat, 19.0, 21.0, 20.9, false];
        yield 'heat, exactly' => [HvacMode::Heat, 19.0, 21.0, 21.0, true];
        yield 'heat, beyond' => [HvacMode::Heat, 19.0, 21.0, 21.4, true];
        yield 'cool, above' => [HvacMode::Cool, 27.0, 24.0, 24.1, false];
        yield 'cool, exactly' => [HvacMode::Cool, 27.0, 24.0, 24.0, true];
        yield 'cool, beyond' => [HvacMode::Cool, 27.0, 24.0, 23.5, true];
    }

    public function testDegreesTravelledIsPositiveInBothDirections(): void
    {
        $heat = new HeatingEpisode(HvacMode::Heat, new Temperature(21.0), new Temperature(19.0), new \DateTimeImmutable());
        $cool = new HeatingEpisode(HvacMode::Cool, new Temperature(24.0), new Temperature(27.0), new \DateTimeImmutable());

        self::assertEqualsWithDelta(2.0, $heat->degreesTravelled(new Temperature(21.0)), 0.0001);
        self::assertEqualsWithDelta(3.0, $cool->degreesTravelled(new Temperature(24.0)), 0.0001);
    }
}
