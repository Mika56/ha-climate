<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Schedule;

use App\Domain\Climate\HvacMode;
use App\Domain\Exception\InvalidValueException;
use App\Domain\HomeAssistant\EntityId;
use App\Domain\Schedule\AnchoredSlotStart;
use App\Domain\Schedule\ResolvedSlot;
use App\Domain\Schedule\ScheduleId;
use App\Domain\Schedule\Weekday;
use App\Domain\Schedule\WeeklySchedule;
use App\Tests\Unit\Domain\Support\ArrayAnchorResolver;
use App\Tests\Unit\Domain\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WeeklySchedule::class)]
#[UsesClass(EntityId::class)]
#[UsesClass(\App\Domain\Preset\PresetKey::class)]
#[UsesClass(AnchoredSlotStart::class)]
#[UsesClass(\App\Domain\Schedule\FixedSlotStart::class)]
#[UsesClass(\App\Domain\Schedule\MinuteOfDay::class)]
#[UsesClass(ResolvedSlot::class)]
#[UsesClass(ScheduleId::class)]
#[UsesClass(\App\Domain\Schedule\ScheduleSlot::class)]
#[UsesClass(Weekday::class)]
final class WeeklyScheduleTest extends TestCase
{
    private const string FORMAT = 'Y-m-d H:i';

    /**
     * 2026-10-26 is a Monday.
     */
    #[DataProvider('provideActiveSlots')]
    public function testActiveSlot(string $at, string $preset, string $start): void
    {
        $active = Fixtures::weeklySchedule()->activeSlotAt(Fixtures::at($at), new ArrayAnchorResolver());

        self::assertResolved($active, $preset, $start);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideActiveSlots(): iterable
    {
        yield 'inside a slot' => ['2026-10-26 07:00', 'comfort', '2026-10-26 06:30'];
        yield 'exactly at slot start' => ['2026-10-26 06:30', 'comfort', '2026-10-26 06:30'];
        yield 'one minute before first slot of day' => ['2026-10-26 06:29', 'night', '2026-10-25 23:00'];
        yield 'midnight carries previous day' => ['2026-10-26 00:00', 'night', '2026-10-25 23:00'];
        yield 'midday' => ['2026-10-26 12:00', 'eco', '2026-10-26 08:30'];
        yield 'last minute of day' => ['2026-10-26 23:59', 'night', '2026-10-26 22:30'];
        yield 'sunday evening' => ['2026-10-25 23:30', 'night', '2026-10-25 23:00'];
        yield 'sunday morning carries saturday' => ['2026-10-25 07:59', 'night', '2026-10-24 23:00'];
        yield 'friday night slot start' => ['2026-10-30 22:30', 'night', '2026-10-30 22:30'];
    }

    #[DataProvider('provideNextSlots')]
    public function testNextSlot(string $after, string $preset, string $start): void
    {
        $next = Fixtures::weeklySchedule()->nextSlotAfter(Fixtures::at($after), new ArrayAnchorResolver());

        self::assertResolved($next, $preset, $start);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideNextSlots(): iterable
    {
        yield 'same day' => ['2026-10-26 07:00', 'eco', '2026-10-26 08:30'];
        yield 'strictly after slot start' => ['2026-10-26 06:30', 'eco', '2026-10-26 08:30'];
        yield 'rolls to next day' => ['2026-10-26 23:00', 'comfort', '2026-10-27 06:30'];
        yield 'week boundary' => ['2026-10-25 23:30', 'comfort', '2026-10-26 06:30'];
        yield 'weekday to weekend' => ['2026-10-30 23:00', 'comfort', '2026-10-31 08:00'];
        yield 'before first slot' => ['2026-10-26 00:00', 'comfort', '2026-10-26 06:30'];
    }

    #[DataProvider('provideSparseActive')]
    public function testActiveSlotWithSlotsOnASingleDay(string $at, string $start): void
    {
        $active = $this->wednesdayOnly()->activeSlotAt(Fixtures::at($at), new ArrayAnchorResolver());

        self::assertResolved($active, 'comfort', $start);
    }

    /**
     * Only Wednesday (2026-10-28) 07:00 has a slot.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function provideSparseActive(): iterable
    {
        yield 'wednesday after slot' => ['2026-10-28 07:00', '2026-10-28 07:00'];
        yield 'wednesday before slot looks back one week' => ['2026-10-28 06:00', '2026-10-21 07:00'];
        yield 'thursday' => ['2026-10-29 12:00', '2026-10-28 07:00'];
        yield 'tuesday looks back six days' => ['2026-10-27 12:00', '2026-10-21 07:00'];
        yield 'monday' => ['2026-10-26 12:00', '2026-10-21 07:00'];
    }

    #[DataProvider('provideSparseNext')]
    public function testNextSlotWithSlotsOnASingleDay(string $after, string $start): void
    {
        $next = $this->wednesdayOnly()->nextSlotAfter(Fixtures::at($after), new ArrayAnchorResolver());

        self::assertResolved($next, 'comfort', $start);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideSparseNext(): iterable
    {
        yield 'two days ahead' => ['2026-10-26 12:00', '2026-10-28 07:00'];
        yield 'same day before slot' => ['2026-10-28 06:00', '2026-10-28 07:00'];
        yield 'same day at slot looks one week ahead' => ['2026-10-28 07:00', '2026-11-04 07:00'];
        yield 'thursday looks six days ahead' => ['2026-10-29 12:00', '2026-11-04 07:00'];
    }

    public function testAnchoredSlotMovingPastAnotherSlotReordersTheDay(): void
    {
        $schedule = $this->anchoredMonday();
        $bedtime = new ArrayAnchorResolver(['input_datetime.bedtime' => 23 * 60]);

        // The anchored slot (23:00) now comes after the fixed 22:00 one.
        self::assertResolved($schedule->activeSlotAt(Fixtures::at('2026-10-26 22:30'), $bedtime), 'night', '2026-10-26 22:00');
        self::assertResolved($schedule->activeSlotAt(Fixtures::at('2026-10-26 23:30'), $bedtime), 'comfort', '2026-10-26 23:00');
        self::assertResolved($schedule->nextSlotAfter(Fixtures::at('2026-10-26 22:30'), $bedtime), 'comfort', '2026-10-26 23:00');
    }

    public function testAnchoredSlotWithUnknownAnchorUsesFallback(): void
    {
        $schedule = $this->anchoredMonday();
        $unknown = new ArrayAnchorResolver();

        // Fallback 21:00 places the anchored slot before the fixed 22:00 one.
        self::assertResolved($schedule->activeSlotAt(Fixtures::at('2026-10-26 21:30'), $unknown), 'comfort', '2026-10-26 21:00');
        self::assertResolved($schedule->activeSlotAt(Fixtures::at('2026-10-26 22:30'), $unknown), 'night', '2026-10-26 22:00');
        self::assertResolved($schedule->nextSlotAfter(Fixtures::at('2026-10-26 21:30'), $unknown), 'night', '2026-10-26 22:00');
    }

    public function testSlotsStartingAtTheSameMinuteKeepDeclarationOrder(): void
    {
        $schedule = new WeeklySchedule(new ScheduleId('ties'), 'Ties', HvacMode::Heat, [
            Fixtures::slot(Weekday::Monday, Fixtures::fixed(8), 'eco'),
            Fixtures::slot(Weekday::Monday, Fixtures::fixed(8), 'comfort'),
        ]);
        $anchors = new ArrayAnchorResolver();

        self::assertResolved($schedule->activeSlotAt(Fixtures::at('2026-10-26 08:00'), $anchors), 'comfort', '2026-10-26 08:00');
        self::assertResolved($schedule->nextSlotAfter(Fixtures::at('2026-10-26 07:00'), $anchors), 'eco', '2026-10-26 08:00');
    }

    public function testDeclarationOrderDoesNotMatterForDifferentTimes(): void
    {
        $schedule = new WeeklySchedule(new ScheduleId('unordered'), 'Unordered', HvacMode::Cool, [
            Fixtures::slot(Weekday::Monday, Fixtures::fixed(18), 'night'),
            Fixtures::slot(Weekday::Monday, Fixtures::fixed(7), 'comfort'),
        ]);

        self::assertResolved($schedule->activeSlotAt(Fixtures::at('2026-10-26 12:00'), new ArrayAnchorResolver()), 'comfort', '2026-10-26 07:00');
    }

    public function testUsesTheTimezoneOfTheGivenInstant(): void
    {
        $active = Fixtures::weeklySchedule()->activeSlotAt(Fixtures::at('2026-10-26 06:45', 'America/New_York'), new ArrayAnchorResolver());

        self::assertResolved($active, 'comfort', '2026-10-26 06:30');
        self::assertSame('America/New_York', $active->start->getTimezone()->getName());
    }

    public function testSpringForwardSlotInTheSkippedHourStartsAfterTheGap(): void
    {
        $schedule = $this->dstSchedule();
        $anchors = new ArrayAnchorResolver();

        // 2026-03-29: clocks jump from 02:00 to 03:00 in Europe/Paris, so 02:30 does not exist.
        $before = $schedule->activeSlotAt(Fixtures::at('2026-03-29 03:10'), $anchors);
        $after = $schedule->activeSlotAt(Fixtures::at('2026-03-29 03:45'), $anchors);
        $next = $schedule->nextSlotAfter(Fixtures::at('2026-03-28 23:00'), $anchors);

        self::assertResolved($before, 'night', '2026-03-28 22:00');
        self::assertResolved($after, 'eco', '2026-03-29 03:30');
        self::assertResolved($next, 'eco', '2026-03-29 03:30');
    }

    public function testFallBackSlotInTheRepeatedHourUsesTheFirstOccurrence(): void
    {
        $schedule = $this->dstSchedule();
        $anchors = new ArrayAnchorResolver();

        // 2026-10-25: clocks go back from 03:00 to 02:00 in Europe/Paris, so 02:30 happens twice.
        $midnight = Fixtures::at('2026-10-25 00:00');
        $firstInstant = $midnight->setTimestamp($midnight->getTimestamp() + 2 * 3600 + 45 * 60);
        $secondInstant = $midnight->setTimestamp($midnight->getTimestamp() + 3 * 3600 + 45 * 60);
        self::assertSame('02:45', $firstInstant->format('H:i'));
        self::assertSame('02:45', $secondInstant->format('H:i'));

        $firstPass = $schedule->activeSlotAt($firstInstant, $anchors);
        $secondPass = $schedule->activeSlotAt($secondInstant, $anchors);
        $later = $schedule->nextSlotAfter(Fixtures::at('2026-10-25 04:00'), $anchors);

        self::assertResolved($firstPass, 'eco', '2026-10-25 02:30');
        self::assertResolved($secondPass, 'eco', '2026-10-25 02:30');
        self::assertResolved($later, 'comfort', '2026-10-25 06:00');
    }

    public function testRejectsEmptySlotList(): void
    {
        $this->expectException(InvalidValueException::class);

        new WeeklySchedule(new ScheduleId('empty'), 'Empty', HvacMode::Heat, []);
    }

    public function testRejectsOffMode(): void
    {
        $this->expectException(InvalidValueException::class);

        new WeeklySchedule(new ScheduleId('off'), 'Off', HvacMode::Off, [
            Fixtures::slot(Weekday::Monday, Fixtures::fixed(8), 'eco'),
        ]);
    }

    public function testExposesItsDefinition(): void
    {
        $schedule = Fixtures::weeklySchedule(HvacMode::Cool);

        self::assertSame('week', (string) $schedule->id);
        self::assertSame('Week', $schedule->name);
        self::assertSame(HvacMode::Cool, $schedule->mode);
        self::assertCount(24, $schedule->slots);
    }

    private static function assertResolved(ResolvedSlot $slot, string $preset, string $start): void
    {
        self::assertSame($preset, (string) $slot->presetKey());
        self::assertSame($start, $slot->start->format(self::FORMAT));
    }

    private function wednesdayOnly(): WeeklySchedule
    {
        return new WeeklySchedule(new ScheduleId('wed'), 'Wednesday', HvacMode::Heat, [
            Fixtures::slot(Weekday::Wednesday, Fixtures::fixed(7), 'comfort'),
        ]);
    }

    private function anchoredMonday(): WeeklySchedule
    {
        return new WeeklySchedule(new ScheduleId('anchored'), 'Anchored', HvacMode::Heat, [
            Fixtures::slot(Weekday::Monday, Fixtures::fixed(22), 'night'),
            Fixtures::slot(Weekday::Monday, new AnchoredSlotStart(new EntityId('input_datetime.bedtime'), 0, 21 * 60), 'comfort'),
        ]);
    }

    private function dstSchedule(): WeeklySchedule
    {
        return new WeeklySchedule(new ScheduleId('dst'), 'DST', HvacMode::Heat, [
            Fixtures::slot(Weekday::Saturday, Fixtures::fixed(22), 'night'),
            Fixtures::slot(Weekday::Sunday, Fixtures::fixed(2, 30), 'eco'),
            Fixtures::slot(Weekday::Sunday, Fixtures::fixed(6), 'comfort'),
        ]);
    }
}
