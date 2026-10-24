<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Decision;

use App\Domain\Decision\VacationPeriod;
use App\Domain\Exception\InvalidValueException;
use App\Tests\Unit\Domain\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(VacationPeriod::class)]
final class VacationPeriodTest extends TestCase
{
    #[DataProvider('provideInstants')]
    public function testIsActiveAt(string $now, bool $expected): void
    {
        $vacation = new VacationPeriod(Fixtures::at('2026-10-26 08:00'), Fixtures::at('2026-11-02 18:00'), true);

        self::assertSame($expected, $vacation->isActiveAt(Fixtures::at($now)));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideInstants(): iterable
    {
        yield 'before' => ['2026-10-26 07:59', false];
        yield 'at start' => ['2026-10-26 08:00', true];
        yield 'during' => ['2026-10-30 12:00', true];
        yield 'at return' => ['2026-11-02 18:00', false];
        yield 'after' => ['2026-11-03 00:00', false];
    }

    public function testReturnMustBeAfterStart(): void
    {
        $this->expectException(InvalidValueException::class);

        new VacationPeriod(Fixtures::at('2026-10-26 08:00'), Fixtures::at('2026-10-26 08:00'), false);
    }
}
