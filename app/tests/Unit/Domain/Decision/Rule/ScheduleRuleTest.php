<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Decision\Rule;

use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Domain\Decision\Rule\ScheduleRule;
use App\Domain\Decision\TargetResolver;
use App\Domain\Exception\PresetTemperatureNotFoundException;
use App\Domain\HomeAssistant\EntityId;
use App\Domain\Room\PresetTemperatures;
use App\Domain\Schedule\AnchoredSlotStart;
use App\Domain\Schedule\ScheduleId;
use App\Domain\Schedule\Weekday;
use App\Domain\Schedule\WeeklySchedule;
use App\Tests\Unit\Domain\Support\ArrayAnchorResolver;
use App\Tests\Unit\Domain\Support\Fixtures;
use App\Tests\Unit\Domain\Support\SpyRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScheduleRule::class)]
#[UsesClass(Season::class)]
#[UsesClass(\App\Domain\Climate\Temperature::class)]
#[UsesClass(\App\Domain\Decision\Decision::class)]
#[UsesClass(\App\Domain\Decision\GlobalPolicy::class)]
#[UsesClass(\App\Domain\Decision\Reason::class)]
#[UsesClass(\App\Domain\Decision\RoomConfiguration::class)]
#[UsesClass(\App\Domain\Decision\RoomEvaluationContext::class)]
#[UsesClass(\App\Domain\Decision\RoomFacts::class)]
#[UsesClass(TargetResolver::class)]
#[UsesClass(\App\Domain\Device\AntiCyclingPolicy::class)]
#[UsesClass(\App\Domain\Device\Device::class)]
#[UsesClass(PresetTemperatureNotFoundException::class)]
#[UsesClass(EntityId::class)]
#[UsesClass(\App\Domain\Preset\PresetKey::class)]
#[UsesClass(PresetTemperatures::class)]
#[UsesClass(\App\Domain\Room\RoomId::class)]
#[UsesClass(AnchoredSlotStart::class)]
#[UsesClass(\App\Domain\Schedule\FixedSlotStart::class)]
#[UsesClass(\App\Domain\Schedule\MinuteOfDay::class)]
#[UsesClass(\App\Domain\Schedule\ResolvedSlot::class)]
#[UsesClass(ScheduleId::class)]
#[UsesClass(\App\Domain\Schedule\ScheduleSlot::class)]
#[UsesClass(Weekday::class)]
#[UsesClass(WeeklySchedule::class)]
final class ScheduleRuleTest extends TestCase
{
    public function testHeatsToTheActivePresetTemperature(): void
    {
        $context = Fixtures::context(Season::Winter, null, Fixtures::weeklySchedule(), Fixtures::at('2026-10-26 07:00'));

        $decision = (new ScheduleRule())->apply($context, static fn () => self::fail('Terminal rule must not delegate.'));

        self::assertSame(HvacMode::Heat, $decision->mode);
        self::assertSame(21.0, $decision->target?->toFloat());
        self::assertSame('reason.schedule', $decision->reason->code);
        self::assertSame(['preset' => 'comfort', 'until' => '08:30'], $decision->reason->params);
    }

    public function testCoolsUsingCoolTemperatures(): void
    {
        $context = Fixtures::context(Season::Summer, null, Fixtures::weeklySchedule(HvacMode::Cool), Fixtures::at('2026-10-26 12:00'));

        $decision = (new ScheduleRule())->apply($context, static fn () => self::fail('Terminal rule must not delegate.'));

        self::assertSame(HvacMode::Cool, $decision->mode);
        self::assertSame(27.0, $decision->target?->toFloat());
        self::assertSame(['preset' => 'eco', 'until' => '17:00'], $decision->reason->params);
    }

    public function testUntilIsTheNextSlotOfTheFollowingDay(): void
    {
        $context = Fixtures::context(Season::Winter, null, Fixtures::weeklySchedule(), Fixtures::at('2026-10-26 23:00'));

        $decision = (new ScheduleRule())->apply($context, static fn () => self::fail('Terminal rule must not delegate.'));

        self::assertSame(17.0, $decision->target?->toFloat());
        self::assertSame(['preset' => 'night', 'until' => '06:30'], $decision->reason->params);
    }

    public function testAnchoredSlotsUseTheAnchorResolver(): void
    {
        $schedule = new WeeklySchedule(new ScheduleId('anchored'), 'Anchored', HvacMode::Heat, [
            Fixtures::slot(Weekday::Monday, Fixtures::fixed(7), 'comfort'),
            Fixtures::slot(Weekday::Monday, new AnchoredSlotStart(new EntityId('input_datetime.bedtime'), -30, 1320), 'night'),
        ]);
        $context = Fixtures::context(
            Season::Winter,
            null,
            $schedule,
            Fixtures::at('2026-10-26 12:00'),
            new ArrayAnchorResolver(['input_datetime.bedtime' => 23 * 60]),
        );

        $decision = (new ScheduleRule())->apply($context, static fn () => self::fail('Terminal rule must not delegate.'));

        self::assertSame(21.0, $decision->target?->toFloat());
        self::assertSame(['preset' => 'comfort', 'until' => '22:30'], $decision->reason->params);
    }

    #[DataProvider('provideScheduleLessContexts')]
    public function testTurnsOffWithoutUsableSchedule(Season $season, ?WeeklySchedule $schedule): void
    {
        $decision = (new ScheduleRule())->apply(
            Fixtures::context($season, null, $schedule),
            static fn () => self::fail('Terminal rule must not delegate.'),
        );

        self::assertTrue($decision->isOff());
        self::assertSame('reason.no_schedule', $decision->reason->code);
    }

    /**
     * @return iterable<string, array{Season, ?WeeklySchedule}>
     */
    public static function provideScheduleLessContexts(): iterable
    {
        yield 'no schedule' => [Season::Winter, null];
        yield 'schedule of the wrong mode' => [Season::Winter, Fixtures::weeklySchedule(HvacMode::Cool)];
        yield 'season off' => [Season::Off, Fixtures::weeklySchedule()];
    }

    public function testMissingPresetTemperatureIsReported(): void
    {
        $room = Fixtures::room(null, PresetTemperatures::empty());
        $context = Fixtures::context(Season::Winter, $room, Fixtures::weeklySchedule());

        $this->expectException(PresetTemperatureNotFoundException::class);

        (new ScheduleRule())->apply($context, static fn () => self::fail('Terminal rule must not delegate.'));
    }

    public function testIsTerminalInAChain(): void
    {
        $after = new SpyRule();
        $context = Fixtures::context(Season::Winter, null, Fixtures::weeklySchedule());

        $decision = (new TargetResolver([new ScheduleRule(), $after]))->resolve($context);

        self::assertSame('reason.schedule', $decision->reason->code);
        self::assertSame(0, $after->calls);
    }
}
