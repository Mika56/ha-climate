<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Decision;

use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Temperature;
use App\Domain\Decision\PreheatLead;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PreheatLead::class)]
#[UsesClass(Temperature::class)]
final class PreheatLeadTest extends TestCase
{
    #[DataProvider('provideLeads')]
    public function testLeadSeconds(HvacMode $mode, float $target, float $reference, ?float $current, ?float $rate, int $maxMinutes, int $expected): void
    {
        $lead = PreheatLead::seconds(
            $mode,
            new Temperature($target),
            new Temperature($reference),
            null === $current ? null : new Temperature($current),
            $rate,
            $maxMinutes,
        );

        self::assertSame($expected, $lead);
    }

    /**
     * @return iterable<string, array{HvacMode, float, float, float|null, float|null, int, int}>
     */
    public static function provideLeads(): iterable
    {
        yield 'heat from the reference, learned rate' => [HvacMode::Heat, 21.0, 18.0, null, 3.0, 120, 3600];
        yield 'heat, default rate when unknown' => [HvacMode::Heat, 21.0, 18.0, null, null, 240, 10800];
        yield 'heat, default rate when not positive' => [HvacMode::Heat, 21.0, 18.0, null, 0.0, 240, 10800];
        yield 'heat, room colder than the reference' => [HvacMode::Heat, 21.0, 18.0, 16.0, 2.5, 240, 7200];
        yield 'heat, room warmer than the reference counts from the reference' => [HvacMode::Heat, 21.0, 18.0, 20.0, 3.0, 120, 3600];
        yield 'heat, capped by the maximum' => [HvacMode::Heat, 21.0, 18.0, null, 1.0, 30, 1800];
        yield 'heat, nothing to cover' => [HvacMode::Heat, 18.0, 18.0, null, 1.0, 120, 0];
        yield 'heat, rounded up to the second' => [HvacMode::Heat, 20.0, 19.0, null, 3.0, 120, 1200];
        yield 'cool from the reference' => [HvacMode::Cool, 24.0, 27.0, null, 3.0, 120, 3600];
        yield 'cool, room hotter than the reference' => [HvacMode::Cool, 24.0, 27.0, 29.0, 1.0, 600, 18000];
        yield 'cool, room cooler than the reference counts from the reference' => [HvacMode::Cool, 24.0, 27.0, 25.0, 3.0, 120, 3600];
        yield 'cool, nothing to cover' => [HvacMode::Cool, 27.0, 27.0, null, 1.0, 120, 0];
    }

    #[DataProvider('provideComparisons')]
    public function testIsHarder(HvacMode $mode, float $reference, float $candidate, bool $expected): void
    {
        self::assertSame($expected, PreheatLead::isHarder($mode, new Temperature($reference), new Temperature($candidate)));
    }

    /**
     * @return iterable<string, array{HvacMode, float, float, bool}>
     */
    public static function provideComparisons(): iterable
    {
        yield 'heat, higher' => [HvacMode::Heat, 18.0, 21.0, true];
        yield 'heat, equal' => [HvacMode::Heat, 18.0, 18.0, false];
        yield 'heat, lower' => [HvacMode::Heat, 21.0, 18.0, false];
        yield 'cool, lower' => [HvacMode::Cool, 27.0, 24.0, true];
        yield 'cool, equal' => [HvacMode::Cool, 27.0, 27.0, false];
        yield 'cool, higher' => [HvacMode::Cool, 24.0, 27.0, false];
    }
}
