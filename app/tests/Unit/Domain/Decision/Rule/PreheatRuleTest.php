<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Decision\Rule;

use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Domain\Decision\Decision;
use App\Domain\Decision\Reason;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\RoomFacts;
use App\Domain\Decision\Rule\PreheatRule;
use App\Domain\Decision\Rule\ScheduleRule;
use App\Domain\Decision\TargetResolver;
use App\Tests\Unit\Domain\Support\Contexts;
use App\Tests\Unit\Domain\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(PreheatRule::class)]
#[UsesClass(ScheduleRule::class)]
#[UsesClass(TargetResolver::class)]
#[UsesNamespace('App\Domain')]
final class PreheatRuleTest extends TestCase
{
    public function testStartsExactlyOneLeadBeforeTheNextSlot(): void
    {
        // Eco 18 until 17:00, then comfort 21: 3 degrees at 3 degrees per hour = one hour of lead.
        $facts = RoomFacts::none()->withHeatingRatePerHour(3.0);

        $at = $this->resolve('2026-10-26 16:00:00', $facts);
        $before = $this->resolve('2026-10-26 15:59:59', $facts);

        self::assertSame(21.0, $at->target?->toFloat());
        self::assertSame('reason.preheat', $at->reason->code);
        self::assertSame(['preset' => 'comfort', 'at' => Fixtures::at('2026-10-26 17:00')->format(\DATE_ATOM)], $at->reason->params);
        self::assertSame(18.0, $before->target?->toFloat());
        self::assertSame('reason.schedule', $before->reason->code);
        self::assertEquals(Fixtures::at('2026-10-26 16:00:00'), $before->reevaluateAt);
    }

    public function testAssumesOneDegreePerHourWithoutLearnedRate(): void
    {
        $room = Contexts::room(true, 240);
        $early = $this->resolve('2026-10-26 14:00:00', null, $room);
        $late = $this->resolve('2026-10-26 13:59:59', null, $room);

        self::assertSame('reason.preheat', $early->reason->code);
        self::assertSame('reason.schedule', $late->reason->code);
        self::assertEquals(Fixtures::at('2026-10-26 14:00:00'), $late->reevaluateAt);
    }

    public function testMeasuresFromTheCurrentTemperatureWhenTheRoomIsColderThanTheTarget(): void
    {
        $facts = RoomFacts::none()->withHeatingRatePerHour(2.5)->withCurrentTemperature(Fixtures::temperature(16.0));

        // 21 - 16 = 5 degrees at 2.5 degrees per hour: two hours.
        $decision = $this->resolve('2026-10-26 14:59:59', $facts);

        self::assertSame('reason.schedule', $decision->reason->code);
        self::assertEquals(Fixtures::at('2026-10-26 15:00:00'), $decision->reevaluateAt);
    }

    public function testTheLeadDoesNotShrinkOnceTheRoomIsWarmerThanTheCurrentTarget(): void
    {
        $facts = RoomFacts::none()->withHeatingRatePerHour(3.0)->withCurrentTemperature(Fixtures::temperature(20.0));

        $decision = $this->resolve('2026-10-26 16:00:00', $facts);

        self::assertSame('reason.preheat', $decision->reason->code);
    }

    public function testTheLeadIsCappedAtTheRoomMaximum(): void
    {
        $room = Contexts::room(true, 30);

        $capped = $this->resolve('2026-10-26 16:29:59', null, $room);
        $started = $this->resolve('2026-10-26 16:30:00', null, $room);

        self::assertSame('reason.schedule', $capped->reason->code);
        self::assertEquals(Fixtures::at('2026-10-26 16:30:00'), $capped->reevaluateAt);
        self::assertSame('reason.preheat', $started->reason->code);
    }

    public function testCoolsAheadOfAColderSlot(): void
    {
        // Cooling: eco 27 until 17:00, then comfort 24; 3 degrees at 3 degrees per hour.
        $facts = RoomFacts::none()->withHeatingRatePerHour(3.0);

        $started = $this->resolve('2026-10-26 16:00:00', $facts, null, Season::Summer);
        $waiting = $this->resolve('2026-10-26 15:00:00', $facts, null, Season::Summer);

        self::assertSame(HvacMode::Cool, $started->mode);
        self::assertSame(24.0, $started->target?->toFloat());
        self::assertSame('reason.preheat', $started->reason->code);
        self::assertSame(27.0, $waiting->target?->toFloat());
        self::assertEquals(Fixtures::at('2026-10-26 16:00:00'), $waiting->reevaluateAt);
    }

    public function testLeavesAnEasierNextSlotAlone(): void
    {
        // Comfort 21 until 08:30, then eco 18.
        $decision = $this->resolve('2026-10-26 08:00:00');

        self::assertSame(21.0, $decision->target?->toFloat());
        self::assertSame('reason.schedule', $decision->reason->code);
        self::assertNull($decision->reevaluateAt);
    }

    public function testLeavesTheRoomAloneWithoutPreheat(): void
    {
        $decision = $this->resolve('2026-10-26 16:59:00', null, Contexts::room(false));

        self::assertSame(18.0, $decision->target?->toFloat());
        self::assertNull($decision->reevaluateAt);
    }

    public function testLeavesTheRoomAloneWhenItHasNoUsableSchedule(): void
    {
        $context = Contexts::make('2026-10-26 16:59:00', Season::Winter, null, Contexts::room(true), Fixtures::weeklySchedule(HvacMode::Cool));

        $decision = (new TargetResolver([new PreheatRule(), new ScheduleRule()]))->resolve($context);

        self::assertTrue($decision->isOff());
        self::assertSame('reason.no_schedule', $decision->reason->code);
    }

    public function testKeepsAnEarlierDeadlineOfTheRulesBelow(): void
    {
        $context = Contexts::make('2026-10-26 16:30:00', Season::Winter, null, Contexts::room(true));
        $below = Decision::heat(Fixtures::temperature(18.0), new Reason('x'))->withReevaluateAt(Fixtures::at('2026-10-26 16:40:00'));

        $decision = (new PreheatRule())->apply($context, static fn (): Decision => $below);

        self::assertEquals(Fixtures::at('2026-10-26 16:40:00'), $decision->reevaluateAt);
        self::assertSame('reason.preheat', $decision->reason->code);
        self::assertSame(21.0, $decision->target?->toFloat());
    }

    public function testRunsJustAboveTheScheduleRule(): void
    {
        self::assertSame(-900, PreheatRule::priority());
        self::assertGreaterThan(ScheduleRule::priority(), PreheatRule::priority());
    }

    private function resolve(string $now, ?RoomFacts $facts = null, ?RoomConfiguration $room = null, Season $season = Season::Winter): Decision
    {
        $context = Contexts::make($now, $season, $facts, $room ?? Contexts::room(true));

        return (new TargetResolver([new PreheatRule(), new ScheduleRule()]))->resolve($context);
    }
}
