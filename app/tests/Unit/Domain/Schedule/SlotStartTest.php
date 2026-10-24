<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Schedule;

use App\Domain\Exception\InvalidValueException;
use App\Domain\HomeAssistant\EntityId;
use App\Domain\Schedule\AnchoredSlotStart;
use App\Domain\Schedule\FixedSlotStart;
use App\Domain\Schedule\MinuteOfDay;
use App\Tests\Unit\Domain\Support\ArrayAnchorResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FixedSlotStart::class)]
#[CoversClass(AnchoredSlotStart::class)]
#[CoversClass(MinuteOfDay::class)]
#[UsesClass(EntityId::class)]
final class SlotStartTest extends TestCase
{
    #[DataProvider('provideValidMinutes')]
    public function testFixedStart(int $minute): void
    {
        self::assertSame($minute, (new FixedSlotStart($minute))->minuteOfDay(new ArrayAnchorResolver()));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideValidMinutes(): iterable
    {
        yield 'midnight' => [0];
        yield 'noon' => [720];
        yield 'last minute' => [1439];
    }

    #[DataProvider('provideInvalidMinutes')]
    public function testFixedStartRejectsOutOfRange(int $minute): void
    {
        $this->expectException(InvalidValueException::class);

        new FixedSlotStart($minute);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideInvalidMinutes(): iterable
    {
        yield 'negative' => [-1];
        yield 'a whole day' => [1440];
    }

    #[DataProvider('provideAnchoredCases')]
    public function testAnchoredStart(?int $anchorMinute, int $offset, int $fallback, int $expected): void
    {
        $anchor = new EntityId('input_datetime.bedtime');
        $start = new AnchoredSlotStart($anchor, $offset, $fallback);
        $resolver = new ArrayAnchorResolver(null === $anchorMinute ? [] : ['input_datetime.bedtime' => $anchorMinute]);

        self::assertSame($expected, $start->minuteOfDay($resolver));
    }

    /**
     * @return iterable<string, array{?int, int, int, int}>
     */
    public static function provideAnchoredCases(): iterable
    {
        yield 'anchor without offset' => [1380, 0, 1260, 1380];
        yield 'positive offset' => [1380, 30, 1260, 1410];
        yield 'negative offset' => [1380, -60, 1260, 1320];
        yield 'clamped to end of day' => [1430, 60, 1260, 1439];
        yield 'clamped to start of day' => [10, -60, 1260, 0];
        yield 'unknown anchor uses fallback' => [null, 30, 1260, 1260];
    }

    public function testAnchorOnlyIsLookedUpForTheGivenEntity(): void
    {
        $start = new AnchoredSlotStart(new EntityId('input_datetime.bedtime'), 0, 100);

        self::assertSame(100, $start->minuteOfDay(new ArrayAnchorResolver(['input_datetime.other' => 500])));
    }

    #[DataProvider('provideInvalidOffsets')]
    public function testAnchoredStartRejectsInvalidOffset(int $offset): void
    {
        $this->expectException(InvalidValueException::class);

        new AnchoredSlotStart(new EntityId('input_datetime.bedtime'), $offset, 0);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideInvalidOffsets(): iterable
    {
        yield 'too early' => [-721];
        yield 'too late' => [721];
    }

    public function testAnchoredStartAcceptsBoundaryOffsets(): void
    {
        $anchor = new EntityId('input_datetime.bedtime');

        self::assertSame(-720, (new AnchoredSlotStart($anchor, -720, 0))->offsetMinutes);
        self::assertSame(720, (new AnchoredSlotStart($anchor, 720, 0))->offsetMinutes);
    }

    public function testAnchoredStartRejectsInvalidFallback(): void
    {
        $this->expectException(InvalidValueException::class);

        new AnchoredSlotStart(new EntityId('input_datetime.bedtime'), 0, 1440);
    }
}
