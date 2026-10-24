<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Decision\Rule;

use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Domain\Decision\Decision;
use App\Domain\Decision\Reason;
use App\Domain\Decision\RoomFacts;
use App\Domain\Decision\Rule\RoomVacancyRule;
use App\Domain\Decision\Rule\ScheduleRule;
use App\Domain\Decision\TargetResolver;
use App\Tests\Unit\Domain\Support\Contexts;
use App\Tests\Unit\Domain\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoomVacancyRule::class)]
#[UsesClass(ScheduleRule::class)]
#[UsesClass(TargetResolver::class)]
#[UsesNamespace('App\Domain')]
final class RoomVacancyRuleTest extends TestCase
{
    public function testAppliesTheVacancyPresetExactlyWhenTheDelayElapses(): void
    {
        // The room is vacant since 06:50, the delay is 10 minutes: comfort 21 gives way to eco 18 at 07:00.
        $facts = RoomFacts::none()->withRoomVacantSince(Fixtures::at('2026-10-26 06:50:00'));

        $decision = $this->resolve('2026-10-26 07:00:00', $facts);

        self::assertSame(HvacMode::Heat, $decision->mode);
        self::assertSame(18.0, $decision->target?->toFloat());
        self::assertSame('reason.room_vacant', $decision->reason->code);
        self::assertSame(['preset' => 'eco', 'since' => Fixtures::at('2026-10-26 06:50:00')->format(\DATE_ATOM)], $decision->reason->params);
    }

    public function testKeepsTheScheduleBeforeTheDelayAndAsksToComeBack(): void
    {
        $facts = RoomFacts::none()->withRoomVacantSince(Fixtures::at('2026-10-26 06:50:00'));

        $decision = $this->resolve('2026-10-26 06:59:59', $facts);

        self::assertSame(21.0, $decision->target?->toFloat());
        self::assertSame('reason.schedule', $decision->reason->code);
        self::assertEquals(Fixtures::at('2026-10-26 07:00:00'), $decision->reevaluateAt);
    }

    public function testDoesNothingWhenTheRoomIsNotVacant(): void
    {
        $decision = $this->resolve('2026-10-26 07:00:00', RoomFacts::none());

        self::assertSame('reason.schedule', $decision->reason->code);
        self::assertNull($decision->reevaluateAt);
    }

    public function testNeverHeatsMoreThanTheScheduleWould(): void
    {
        // Night is 17, colder than the eco vacancy preset (18): vacancy must not raise it.
        $facts = RoomFacts::none()->withRoomVacantSince(Fixtures::at('2026-10-26 22:00:00'));

        $decision = $this->resolve('2026-10-26 23:00:00', $facts);

        self::assertSame(17.0, $decision->target?->toFloat());
        self::assertSame('reason.schedule', $decision->reason->code);
    }

    public function testCoolsLessWhenVacant(): void
    {
        // Cooling comfort is 24 at 07:00, the vacancy preset is eco 27.
        $facts = RoomFacts::none()->withRoomVacantSince(Fixtures::at('2026-10-26 06:50:00'));

        $decision = $this->resolve('2026-10-26 07:00:00', $facts, Season::Summer);

        self::assertSame(HvacMode::Cool, $decision->mode);
        self::assertSame(27.0, $decision->target?->toFloat());
        self::assertSame('reason.room_vacant', $decision->reason->code);
    }

    public function testNeverCoolsMoreThanTheScheduleWould(): void
    {
        // Eco is already the target (27) in the middle of the day.
        $facts = RoomFacts::none()->withRoomVacantSince(Fixtures::at('2026-10-26 11:00:00'));

        $decision = $this->resolve('2026-10-26 12:00:00', $facts, Season::Summer);

        self::assertSame('reason.schedule', $decision->reason->code);
    }

    public function testLeavesAnOffDecisionAlone(): void
    {
        $facts = RoomFacts::none()->withRoomVacantSince(Fixtures::at('2026-10-26 06:00:00'));
        $context = Contexts::make('2026-10-26 07:00:00', Season::Winter, $facts);

        $decision = (new RoomVacancyRule())->apply($context, static fn (): Decision => Decision::off(new Reason('reason.house_empty')));

        self::assertSame('reason.house_empty', $decision->reason->code);
    }

    public function testLeavesATargetAloneWhenTheSeasonIsOff(): void
    {
        $facts = RoomFacts::none()->withRoomVacantSince(Fixtures::at('2026-10-26 06:00:00'));
        $context = Contexts::make('2026-10-26 07:00:00', Season::Off, $facts);

        $decision = (new RoomVacancyRule())->apply($context, static fn (): Decision => Decision::heat(Fixtures::temperature(21.0), new Reason('other')));

        self::assertSame('other', $decision->reason->code);
    }

    public function testKeepsTheDeadlineOfTheRulesBelow(): void
    {
        $facts = RoomFacts::none()->withRoomVacantSince(Fixtures::at('2026-10-26 06:00:00'));
        $context = Contexts::make('2026-10-26 07:00:00', Season::Winter, $facts);
        $below = Decision::heat(Fixtures::temperature(21.0), new Reason('other'))->withReevaluateAt(Fixtures::at('2026-10-26 08:00:00'));

        $decision = (new RoomVacancyRule())->apply($context, static fn (): Decision => $below);

        self::assertSame('reason.room_vacant', $decision->reason->code);
        self::assertEquals(Fixtures::at('2026-10-26 08:00:00'), $decision->reevaluateAt);
    }

    public function testPriority(): void
    {
        self::assertSame(400, RoomVacancyRule::priority());
    }

    private function resolve(string $now, RoomFacts $facts, Season $season = Season::Winter): Decision
    {
        return (new TargetResolver([new RoomVacancyRule(), new ScheduleRule()]))->resolve(Contexts::make($now, $season, $facts));
    }
}
