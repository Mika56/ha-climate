<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Schedule;

use App\Domain\Schedule\Weekday;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Weekday::class)]
final class WeekdayTest extends TestCase
{
    #[DataProvider('provideDates')]
    public function testFromDate(string $date, Weekday $expected): void
    {
        self::assertSame($expected, Weekday::fromDate(new \DateTimeImmutable($date)));
    }

    /**
     * @return iterable<string, array{string, Weekday}>
     */
    public static function provideDates(): iterable
    {
        yield 'monday' => ['2026-10-26 12:00', Weekday::Monday];
        yield 'wednesday' => ['2026-10-28 00:00', Weekday::Wednesday];
        yield 'sunday' => ['2026-10-25 23:59', Weekday::Sunday];
    }

    public function testPreviousAndNextWrapAroundTheWeek(): void
    {
        self::assertSame(Weekday::Sunday, Weekday::Monday->previous());
        self::assertSame(Weekday::Monday, Weekday::Sunday->next());
        self::assertSame(Weekday::Tuesday, Weekday::Wednesday->previous());
        self::assertSame(Weekday::Thursday, Weekday::Wednesday->next());
    }

    public function testNextThenPreviousIsIdentity(): void
    {
        foreach (Weekday::cases() as $day) {
            self::assertSame($day, $day->next()->previous());
            self::assertSame($day, $day->previous()->next());
        }
    }
}
