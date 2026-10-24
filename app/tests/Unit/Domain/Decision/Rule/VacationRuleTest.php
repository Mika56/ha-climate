<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Decision\Rule;

use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Domain\Decision\Decision;
use App\Domain\Decision\Reason;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\RoomFacts;
use App\Domain\Decision\Rule\ScheduleRule;
use App\Domain\Decision\Rule\VacationRule;
use App\Domain\Decision\TargetResolver;
use App\Domain\Decision\VacationPeriod;
use App\Tests\Unit\Domain\Support\Contexts;
use App\Tests\Unit\Domain\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

/**
 * Vacation from Monday 2026-10-26 12:00 to Friday 2026-10-30 18:00. On Friday, eco 18 runs until 17:00 and
 * comfort 21 from then on, so a room preheating at 3 degrees per hour from the away preset (15) needs two hours.
 */
#[CoversClass(VacationRule::class)]
#[UsesClass(ScheduleRule::class)]
#[UsesClass(TargetResolver::class)]
#[UsesNamespace('App\Domain')]
final class VacationRuleTest extends TestCase
{
    public function testWaitsForTheStart(): void
    {
        $decision = $this->resolve('2026-10-26 11:59:59');

        self::assertSame('reason.schedule', $decision->reason->code);
        self::assertEquals(Fixtures::at('2026-10-26 12:00:00'), $decision->reevaluateAt);
    }

    public function testSwitchesOffAtTheStartAndAsksToComeBackAtTheEndOfTheLead(): void
    {
        $decision = $this->resolve('2026-10-26 12:00:00');

        self::assertTrue($decision->isOff());
        self::assertSame('reason.vacation', $decision->reason->code);
        self::assertSame(['returnAt' => Fixtures::at('2026-10-30 18:00:00')->format(\DATE_ATOM)], $decision->reason->params);
        self::assertEquals(Fixtures::at('2026-10-30 16:00:00'), $decision->reevaluateAt);
    }

    public function testKeepsTheAbsentPresetWhenNotSwitchingOff(): void
    {
        $decision = $this->resolve('2026-10-27 12:00:00', offDuringVacation: false);

        self::assertSame(HvacMode::Heat, $decision->mode);
        self::assertSame(15.0, $decision->target?->toFloat());
        self::assertSame('reason.vacation', $decision->reason->code);
    }

    public function testKeepsTheAbsentPresetForCooling(): void
    {
        $decision = $this->resolve('2026-10-27 12:00:00', season: Season::Summer, offDuringVacation: false);

        self::assertSame(HvacMode::Cool, $decision->mode);
        self::assertSame(30.0, $decision->target?->toFloat());
    }

    public function testStaysAwayUntilTheLeadStartsThenPreheatsForTheReturn(): void
    {
        $before = $this->resolve('2026-10-30 15:59:59');
        $started = $this->resolve('2026-10-30 16:00:00');

        self::assertSame('reason.vacation', $before->reason->code);
        self::assertSame('reason.preheat', $started->reason->code);
        self::assertSame(HvacMode::Heat, $started->mode);
        // The schedule says eco (18) at 16:00 but the slot at the return is comfort (21).
        self::assertSame(21.0, $started->target?->toFloat());
        self::assertSame(['preset' => 'comfort', 'at' => Fixtures::at('2026-10-30 18:00:00')->format(\DATE_ATOM)], $started->reason->params);
        self::assertEquals(Fixtures::at('2026-10-30 18:00:00'), $started->reevaluateAt);
    }

    public function testLetsTheScheduleRunWhenItIsAlreadyAsDemandingAsAtTheReturn(): void
    {
        // Back on Friday 12:00 (eco 18): the lead is one hour (18 - 15 at 3 degrees per hour).
        $facts = RoomFacts::none()->withVacation($this->vacation(returnAt: '2026-10-30 12:00:00'))->withHeatingRatePerHour(3.0);
        $context = Contexts::make('2026-10-30 11:30:00', facts: $facts, room: Contexts::room(true));

        $decision = (new TargetResolver([new VacationRule(), new ScheduleRule()]))->resolve($context);

        self::assertSame('reason.schedule', $decision->reason->code);
        self::assertSame(18.0, $decision->target?->toFloat());
    }

    public function testTheLeadStartsEarlierForAColdRoom(): void
    {
        // 21 - 12 = 9 degrees at 3 degrees per hour: three hours, so 15:00.
        $facts = RoomFacts::none()
            ->withVacation($this->vacation())
            ->withHeatingRatePerHour(3.0)
            ->withCurrentTemperature(Fixtures::temperature(12.0));

        $decision = (new TargetResolver([new VacationRule(), new ScheduleRule()]))
            ->resolve(Contexts::make('2026-10-28 12:00:00', facts: $facts, room: Contexts::room(true, 300)));

        self::assertEquals(Fixtures::at('2026-10-30 15:00:00'), $decision->reevaluateAt);
    }

    public function testWithoutPreheatTheScheduleRestartsAtTheReturn(): void
    {
        $room = Contexts::room(false);

        $before = $this->resolve('2026-10-30 17:59:59', room: $room);
        $after = $this->resolve('2026-10-30 18:00:00', room: $room);

        self::assertSame('reason.vacation', $before->reason->code);
        self::assertEquals(Fixtures::at('2026-10-30 18:00:00'), $before->reevaluateAt);
        self::assertSame('reason.schedule', $after->reason->code);
        self::assertNull($after->reevaluateAt);
    }

    public function testWithoutScheduleThereIsNothingToPreheat(): void
    {
        $facts = RoomFacts::none()->withVacation($this->vacation());
        $context = Contexts::make('2026-10-30 17:00:00', facts: $facts, room: Contexts::room(true), schedule: Fixtures::weeklySchedule(HvacMode::Cool));

        $decision = (new TargetResolver([new VacationRule(), new ScheduleRule()]))->resolve($context);

        self::assertSame('reason.vacation', $decision->reason->code);
        self::assertEquals(Fixtures::at('2026-10-30 18:00:00'), $decision->reevaluateAt);
    }

    public function testAfterTheAwayPhaseWithoutScheduleTheChainRuns(): void
    {
        $facts = RoomFacts::none()->withVacation($this->vacation());
        $context = Contexts::make('2026-10-30 18:00:00', facts: $facts, room: Contexts::room(true), schedule: Fixtures::weeklySchedule(HvacMode::Cool));

        $decision = (new TargetResolver([new VacationRule(), new ScheduleRule()]))->resolve($context);

        self::assertSame('reason.no_schedule', $decision->reason->code);
    }

    public function testLeavesAnOffDecisionOfTheRulesBelowAloneInTheLeadWindow(): void
    {
        $facts = RoomFacts::none()->withVacation($this->vacation())->withHeatingRatePerHour(3.0);
        $context = Contexts::make('2026-10-30 16:00:00', facts: $facts, room: Contexts::room(true));

        $decision = (new VacationRule())->apply($context, static fn (): Decision => Decision::off(new Reason('reason.opening')));

        self::assertTrue($decision->isOff());
        self::assertSame('reason.opening', $decision->reason->code);
        self::assertEquals(Fixtures::at('2026-10-30 18:00:00'), $decision->reevaluateAt);
    }

    public function testIsIgnoredWithoutVacationOrWithTheSeasonOff(): void
    {
        $none = (new TargetResolver([new VacationRule(), new ScheduleRule()]))->resolve(Contexts::make('2026-10-27 12:00:00'));
        $off = (new VacationRule())->apply(
            Contexts::make('2026-10-27 12:00:00', Season::Off, RoomFacts::none()->withVacation($this->vacation())),
            static fn (): Decision => Decision::off(new Reason('reason.season_off')),
        );

        self::assertSame('reason.schedule', $none->reason->code);
        self::assertSame('reason.season_off', $off->reason->code);
    }

    public function testPriority(): void
    {
        self::assertSame(800, VacationRule::priority());
    }

    private function resolve(string $now, Season $season = Season::Winter, bool $offDuringVacation = true, ?RoomConfiguration $room = null): Decision
    {
        $facts = RoomFacts::none()->withVacation($this->vacation(offDuringVacation: $offDuringVacation))->withHeatingRatePerHour(3.0);
        $context = Contexts::make($now, $season, $facts, $room ?? Contexts::room(true));

        return (new TargetResolver([new VacationRule(), new ScheduleRule()]))->resolve($context);
    }

    private function vacation(string $returnAt = '2026-10-30 18:00:00', bool $offDuringVacation = true): VacationPeriod
    {
        return new VacationPeriod(Fixtures::at('2026-10-26 12:00:00'), Fixtures::at($returnAt), $offDuringVacation);
    }
}
