<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Decision\Rule;

use App\Domain\Climate\Season;
use App\Domain\Decision\Decision;
use App\Domain\Decision\Reason;
use App\Domain\Decision\Rule\SeasonOffRule;
use App\Domain\Decision\TargetResolver;
use App\Tests\Unit\Domain\Support\Fixtures;
use App\Tests\Unit\Domain\Support\SpyRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SeasonOffRule::class)]
#[UsesClass(\App\Domain\Climate\Temperature::class)]
#[UsesClass(Decision::class)]
#[UsesClass(\App\Domain\Decision\GlobalPolicy::class)]
#[UsesClass(Reason::class)]
#[UsesClass(\App\Domain\Decision\RoomConfiguration::class)]
#[UsesClass(\App\Domain\Decision\RoomEvaluationContext::class)]
#[UsesClass(\App\Domain\Decision\RoomFacts::class)]
#[UsesClass(TargetResolver::class)]
#[UsesClass(\App\Domain\Device\AntiCyclingPolicy::class)]
#[UsesClass(\App\Domain\Device\Device::class)]
#[UsesClass(\App\Domain\HomeAssistant\EntityId::class)]
#[UsesClass(\App\Domain\Preset\PresetKey::class)]
#[UsesClass(\App\Domain\Room\PresetTemperatures::class)]
#[UsesClass(\App\Domain\Room\RoomId::class)]
#[UsesClass(\App\Domain\Schedule\ScheduleId::class)]
final class SeasonOffRuleTest extends TestCase
{
    public function testSeasonOffStopsTheChain(): void
    {
        $next = new SpyRule();

        $decision = (new TargetResolver([new SeasonOffRule(), $next]))->resolve(Fixtures::context(Season::Off));

        self::assertTrue($decision->isOff());
        self::assertSame('reason.season_off', $decision->reason->code);
        self::assertSame(0, $next->calls);
    }

    #[DataProvider('provideActiveSeasons')]
    public function testActiveSeasonDelegates(Season $season): void
    {
        $next = new SpyRule(Decision::off(new Reason('reason.next')));

        $decision = (new TargetResolver([new SeasonOffRule(), $next]))->resolve(Fixtures::context($season));

        self::assertSame('reason.next', $decision->reason->code);
        self::assertSame(1, $next->calls);
    }

    /**
     * @return iterable<string, array{Season}>
     */
    public static function provideActiveSeasons(): iterable
    {
        yield 'winter' => [Season::Winter];
        yield 'summer' => [Season::Summer];
    }

    public function testPriority(): void
    {
        self::assertSame(1000, SeasonOffRule::priority());
    }
}
