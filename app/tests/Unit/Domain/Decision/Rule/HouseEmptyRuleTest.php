<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Decision\Rule;

use App\Domain\Decision\Decision;
use App\Domain\Decision\RoomFacts;
use App\Domain\Decision\Rule\HouseEmptyRule;
use App\Domain\Decision\Rule\ScheduleRule;
use App\Domain\Decision\TargetResolver;
use App\Tests\Unit\Domain\Support\Contexts;
use App\Tests\Unit\Domain\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(HouseEmptyRule::class)]
#[UsesClass(ScheduleRule::class)]
#[UsesClass(TargetResolver::class)]
#[UsesNamespace('App\Domain')]
final class HouseEmptyRuleTest extends TestCase
{
    public function testSwitchesOffExactlyWhenTheDelayElapses(): void
    {
        $facts = RoomFacts::none()->withHouseEmptySince(Fixtures::at('2026-10-26 07:00:00'));

        $decision = $this->resolve('2026-10-26 07:30:00', $facts);

        self::assertTrue($decision->isOff());
        self::assertSame('reason.house_empty', $decision->reason->code);
        self::assertSame(['since' => Fixtures::at('2026-10-26 07:00:00')->format(\DATE_ATOM)], $decision->reason->params);
    }

    public function testKeepsTheScheduleOneSecondBeforeAndAsksToComeBackAtTheDeadline(): void
    {
        $facts = RoomFacts::none()->withHouseEmptySince(Fixtures::at('2026-10-26 07:00:00'));

        $decision = $this->resolve('2026-10-26 07:29:59', $facts);

        self::assertSame('reason.schedule', $decision->reason->code);
        self::assertSame(21.0, $decision->target?->toFloat());
        self::assertEquals(Fixtures::at('2026-10-26 07:30:00'), $decision->reevaluateAt);
    }

    public function testDoesNothingWhenSomeoneIsHome(): void
    {
        $decision = $this->resolve('2026-10-26 07:30:00', RoomFacts::none());

        self::assertSame('reason.schedule', $decision->reason->code);
        self::assertNull($decision->reevaluateAt);
    }

    public function testAZeroDelaySwitchesOffImmediately(): void
    {
        $facts = RoomFacts::none()->withHouseEmptySince(Fixtures::at('2026-10-26 07:00:00'));

        $decision = (new TargetResolver([new HouseEmptyRule(), new ScheduleRule()]))
            ->resolve(Contexts::make('2026-10-26 07:00:00', facts: $facts, houseEmptyDelaySeconds: 0));

        self::assertSame('reason.house_empty', $decision->reason->code);
    }

    public function testPriority(): void
    {
        self::assertSame(700, HouseEmptyRule::priority());
    }

    private function resolve(string $now, RoomFacts $facts): Decision
    {
        return (new TargetResolver([new HouseEmptyRule(), new ScheduleRule()]))->resolve(Contexts::make($now, facts: $facts));
    }
}
