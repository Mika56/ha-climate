<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Decision\Rule;

use App\Domain\Climate\Season;
use App\Domain\Climate\Temperature;
use App\Domain\Climate\TemperatureDelta;
use App\Domain\Decision\ActiveOverride;
use App\Domain\Decision\Decision;
use App\Domain\Decision\Reason;
use App\Domain\Decision\RoomEvaluationContext;
use App\Domain\Decision\RoomFacts;
use App\Domain\Decision\Rule\OverrideRule;
use App\Domain\Decision\TargetResolver;
use App\Tests\Unit\Domain\Support\Fixtures;
use App\Tests\Unit\Domain\Support\SpyRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(OverrideRule::class)]
#[UsesNamespace('App\Domain')]
final class OverrideRuleTest extends TestCase
{
    public function testWithoutOverrideTheRuleDelegates(): void
    {
        $next = new SpyRule(Decision::heat(Fixtures::temperature(21.0), new Reason('reason.schedule')));

        $decision = self::resolve($next, self::context(null));

        self::assertSame('reason.schedule', $decision->reason->code);
        self::assertNull($decision->reevaluateAt);
    }

    public function testAnEndedOverrideIsIgnored(): void
    {
        $now = Fixtures::at('2026-10-26 07:00');
        $next = new SpyRule(Decision::heat(Fixtures::temperature(21.0), new Reason('reason.schedule')));

        $decision = self::resolve($next, self::context(ActiveOverride::boost(new TemperatureDelta(2.0), $now), $now));

        self::assertSame('reason.schedule', $decision->reason->code);
        self::assertSame(21.0, $decision->target?->toFloat());
    }

    public function testAnOverrideIsIgnoredWhenTheSeasonHasNoMode(): void
    {
        $next = new SpyRule(Decision::off(new Reason('reason.next')));
        $override = ActiveOverride::manual(Fixtures::temperature(23.0), Fixtures::at('2026-10-26 09:00'));

        $decision = self::resolve($next, self::context($override, season: Season::Off));

        self::assertSame('reason.next', $decision->reason->code);
    }

    public function testABoostRaisesTheBaseTargetWhenHeating(): void
    {
        $next = new SpyRule(Decision::heat(Fixtures::temperature(21.0), new Reason('reason.schedule')));
        $endsAt = Fixtures::at('2026-10-26 08:30');

        $decision = self::resolve($next, self::context(ActiveOverride::boost(new TemperatureDelta(1.5), $endsAt)));

        self::assertSame(22.5, $decision->target?->toFloat());
        self::assertSame('reason.boost', $decision->reason->code);
        self::assertSame(['delta' => 1.5, 'until' => '2026-10-26T08:30:00+01:00'], $decision->reason->params);
        self::assertEquals($endsAt, $decision->reevaluateAt);
    }

    public function testABoostLowersTheBaseTargetWhenCooling(): void
    {
        $next = new SpyRule(Decision::cool(Fixtures::temperature(24.0), new Reason('reason.schedule')));
        $override = ActiveOverride::boost(new TemperatureDelta(2.0), Fixtures::at('2026-10-26 08:30'));

        $decision = self::resolve($next, self::context($override, season: Season::Summer));

        self::assertSame(22.0, $decision->target?->toFloat());
        self::assertSame(\App\Domain\Climate\HvacMode::Cool, $decision->mode);
    }

    public function testABoostIsClampedToTheTemperatureRange(): void
    {
        $hot = new SpyRule(Decision::heat(Fixtures::temperature(34.0), new Reason('reason.schedule')));
        $cold = new SpyRule(Decision::cool(Fixtures::temperature(6.0), new Reason('reason.schedule')));
        $override = ActiveOverride::boost(new TemperatureDelta(3.0), Fixtures::at('2026-10-26 08:30'));

        self::assertSame(Temperature::MAX, self::resolve($hot, self::context($override))->target?->toFloat());
        self::assertSame(Temperature::MIN, self::resolve($cold, self::context($override, season: Season::Summer))->target?->toFloat());
    }

    public function testABoostNeverSwitchesAnOffBaseOn(): void
    {
        $next = new SpyRule(Decision::off(new Reason('reason.no_schedule')));
        $endsAt = Fixtures::at('2026-10-26 08:30');

        $decision = self::resolve($next, self::context(ActiveOverride::boost(new TemperatureDelta(2.0), $endsAt)));

        self::assertTrue($decision->isOff());
        self::assertSame('reason.no_schedule', $decision->reason->code);
        self::assertEquals($endsAt, $decision->reevaluateAt);
    }

    public function testABoostKeepsTheDeadlineOfTheBaseDecisionWhenEarlier(): void
    {
        $earlier = Fixtures::at('2026-10-26 07:30');
        $next = new SpyRule(Decision::heat(Fixtures::temperature(21.0), new Reason('reason.schedule'))->withReevaluateAt($earlier));

        $decision = self::resolve($next, self::context(ActiveOverride::boost(new TemperatureDelta(1.0), Fixtures::at('2026-10-26 08:30'))));

        self::assertEquals($earlier, $decision->reevaluateAt);
    }

    public function testAManualOverrideAnswersByItselfWithItsAbsoluteTarget(): void
    {
        $next = new SpyRule(Decision::heat(Fixtures::temperature(21.0), new Reason('reason.schedule')));
        $override = ActiveOverride::manual(Fixtures::temperature(24.5), Fixtures::at('2026-10-26 09:00'));

        $decision = self::resolve($next, self::context($override));

        self::assertSame(0, $next->calls);
        self::assertSame(24.5, $decision->target?->toFloat());
        self::assertSame('reason.manual_override', $decision->reason->code);
        self::assertSame(['until' => '2026-10-26T09:00:00+01:00'], $decision->reason->params);
    }

    public function testAManualOverrideInSummerCools(): void
    {
        $override = ActiveOverride::manual(Fixtures::temperature(25.0), Fixtures::at('2026-10-26 09:00'));

        $decision = self::resolve(new SpyRule(), self::context($override, season: Season::Summer));

        self::assertSame(\App\Domain\Climate\HvacMode::Cool, $decision->mode);
        self::assertSame(25.0, $decision->target?->toFloat());
    }

    public function testAManualOffSwitchesTheRoomOff(): void
    {
        $endsAt = Fixtures::at('2026-10-26 09:00');

        $decision = self::resolve(new SpyRule(), self::context(ActiveOverride::manualOff($endsAt)));

        self::assertTrue($decision->isOff());
        self::assertSame('reason.manual_override', $decision->reason->code);
        self::assertEquals($endsAt, $decision->reevaluateAt);
    }

    public function testAnOverrideWithoutEndAsksForNoReevaluation(): void
    {
        $decision = self::resolve(new SpyRule(), self::context(ActiveOverride::manual(Fixtures::temperature(22.0), null, true)));

        self::assertNull($decision->reevaluateAt);
        self::assertNull($decision->reason->params['until']);
    }

    public function testTheUntilTimeIsExpressedInTheTimeZoneOfTheEvaluation(): void
    {
        $override = ActiveOverride::manual(Fixtures::temperature(22.0), Fixtures::at('2026-10-26 08:30', 'UTC'));

        $decision = self::resolve(new SpyRule(), self::context($override));

        self::assertSame('2026-10-26T09:30:00+01:00', $decision->reason->params['until']);
    }

    public function testPriority(): void
    {
        self::assertSame(500, OverrideRule::priority());
    }

    private static function resolve(SpyRule $next, RoomEvaluationContext $context): Decision
    {
        return (new TargetResolver([new OverrideRule(), $next]))->resolve($context);
    }

    private static function context(?ActiveOverride $override, ?\DateTimeImmutable $now = null, Season $season = Season::Winter): RoomEvaluationContext
    {
        $base = Fixtures::context($season, now: $now);

        return new RoomEvaluationContext(
            $base->now,
            $base->season,
            $base->room,
            RoomFacts::none()->withOverride($override),
            $base->schedule,
            $base->anchors,
            $base->policy,
        );
    }
}
