<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Decision\Rule;

use App\Domain\Climate\Season;
use App\Domain\Decision\Decision;
use App\Domain\Decision\Reason;
use App\Domain\Decision\RoomEvaluationContext;
use App\Domain\Decision\RoomFacts;
use App\Domain\Decision\Rule\OpeningRule;
use App\Domain\Decision\TargetResolver;
use App\Tests\Unit\Domain\Support\Fixtures;
use App\Tests\Unit\Domain\Support\SpyRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpeningRule::class)]
#[UsesNamespace('App\Domain')]
final class OpeningRuleTest extends TestCase
{
    public function testWithNothingOpenTheChainCarriesOnUntouched(): void
    {
        $next = new SpyRule(Decision::off(new Reason('reason.next')));

        $decision = (new TargetResolver([new OpeningRule(), $next]))->resolve(Fixtures::context());

        self::assertSame('reason.next', $decision->reason->code);
        self::assertNull($decision->reevaluateAt);
    }

    public function testAnOpeningNotOpenLongEnoughAsksToBeEvaluatedAgainWhenTheDelayElapses(): void
    {
        $next = new SpyRule(Decision::off(new Reason('reason.next')));
        $now = Fixtures::at('2026-10-26 07:01:59');

        $decision = (new TargetResolver([new OpeningRule(), $next]))->resolve(self::context($now, '2026-10-26 07:00:00'));

        self::assertSame('reason.next', $decision->reason->code);
        self::assertEquals(Fixtures::at('2026-10-26 07:02:00'), $decision->reevaluateAt);
    }

    public function testAnOpeningOpenForTheCutDelayStopsTheChain(): void
    {
        $next = new SpyRule();

        $decision = (new TargetResolver([new OpeningRule(), $next]))->resolve(self::context(Fixtures::at('2026-10-26 07:02:00'), '2026-10-26 07:00:00'));

        self::assertTrue($decision->isOff());
        self::assertSame('reason.opening_open', $decision->reason->code);
        self::assertSame(['since' => '2026-10-26T07:00:00+01:00'], $decision->reason->params);
        self::assertSame(0, $next->calls);
    }

    public function testAnEarlierDeadlineFromAnotherRuleIsKept(): void
    {
        $earlier = Fixtures::at('2026-10-26 07:01:00');
        $next = new SpyRule(Decision::off(new Reason('reason.next'))->withReevaluateAt($earlier));

        $decision = (new TargetResolver([new OpeningRule(), $next]))->resolve(self::context(Fixtures::at('2026-10-26 07:00:30'), '2026-10-26 07:00:00'));

        self::assertEquals($earlier, $decision->reevaluateAt);
    }

    public function testPriority(): void
    {
        self::assertSame(600, OpeningRule::priority());
    }

    private static function context(\DateTimeImmutable $now, string $openSince): RoomEvaluationContext
    {
        $base = Fixtures::context(Season::Winter, null, null, $now);

        return new RoomEvaluationContext(
            $base->now,
            $base->season,
            $base->room,
            RoomFacts::none()->withOpeningOpenSince(Fixtures::at($openSince)),
            $base->schedule,
            $base->anchors,
            $base->policy,
        );
    }
}
