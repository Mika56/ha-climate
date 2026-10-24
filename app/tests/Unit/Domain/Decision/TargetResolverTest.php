<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Decision;

use App\Domain\Climate\HvacMode;
use App\Domain\Decision\Decision;
use App\Domain\Decision\Reason;
use App\Domain\Decision\TargetResolver;
use App\Tests\Unit\Domain\Support\Fixtures;
use App\Tests\Unit\Domain\Support\SpyRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TargetResolver::class)]
#[UsesClass(\App\Domain\Climate\Temperature::class)]
#[UsesClass(Decision::class)]
#[UsesClass(\App\Domain\Decision\GlobalPolicy::class)]
#[UsesClass(Reason::class)]
#[UsesClass(\App\Domain\Decision\RoomConfiguration::class)]
#[UsesClass(\App\Domain\Decision\RoomEvaluationContext::class)]
#[UsesClass(\App\Domain\Decision\RoomFacts::class)]
#[UsesClass(\App\Domain\Device\AntiCyclingPolicy::class)]
#[UsesClass(\App\Domain\Device\Device::class)]
#[UsesClass(\App\Domain\HomeAssistant\EntityId::class)]
#[UsesClass(\App\Domain\Preset\PresetKey::class)]
#[UsesClass(\App\Domain\Room\PresetTemperatures::class)]
#[UsesClass(\App\Domain\Room\RoomId::class)]
#[UsesClass(\App\Domain\Schedule\ScheduleId::class)]
final class TargetResolverTest extends TestCase
{
    public function testEmptyChainTurnsOff(): void
    {
        $decision = (new TargetResolver([]))->resolve(Fixtures::context());

        self::assertSame(HvacMode::Off, $decision->mode);
        self::assertSame('reason.no_rule', $decision->reason->code);
    }

    public function testChainWithoutTerminalRuleTurnsOff(): void
    {
        $first = new SpyRule();
        $second = new SpyRule();

        $decision = (new TargetResolver([$first, $second]))->resolve(Fixtures::context());

        self::assertSame('reason.no_rule', $decision->reason->code);
        self::assertSame(1, $first->calls);
        self::assertSame(1, $second->calls);
    }

    public function testTerminalRuleShortCircuitsTheRest(): void
    {
        $before = new SpyRule();
        $terminal = new SpyRule(Decision::heat(Fixtures::temperature(20.0), new Reason('reason.first')));
        $after = new SpyRule(Decision::off(new Reason('reason.never')));

        $decision = (new TargetResolver([$before, $terminal, $after]))->resolve(Fixtures::context());

        self::assertSame('reason.first', $decision->reason->code);
        self::assertSame(1, $before->calls);
        self::assertSame(1, $terminal->calls);
        self::assertSame(0, $after->calls);
    }

    public function testRulesRunInTheGivenOrder(): void
    {
        $a = new SpyRule(Decision::off(new Reason('reason.a')));
        $b = new SpyRule(Decision::off(new Reason('reason.b')));

        self::assertSame('reason.a', (new TargetResolver([$a, $b]))->resolve(Fixtures::context())->reason->code);
        self::assertSame('reason.b', (new TargetResolver([$b, $a]))->resolve(Fixtures::context())->reason->code);
    }

    public function testAcceptsAnyIterableAndIsReusable(): void
    {
        $terminal = new SpyRule(Decision::off(new Reason('reason.generated')));
        $generator = (static function () use ($terminal) {
            yield 'ignored-key' => new SpyRule();
            yield 'other-key' => $terminal;
        })();

        $resolver = new TargetResolver($generator);

        self::assertSame('reason.generated', $resolver->resolve(Fixtures::context())->reason->code);
        self::assertSame('reason.generated', $resolver->resolve(Fixtures::context())->reason->code);
        self::assertSame(2, $terminal->calls);
    }

    public function testRulesReceiveTheContext(): void
    {
        $context = Fixtures::context();
        $rule = new class implements \App\Domain\Decision\RoomRuleInterface {
            public ?\App\Domain\Decision\RoomEvaluationContext $seen = null;

            public static function priority(): int
            {
                return 0;
            }

            public function apply(\App\Domain\Decision\RoomEvaluationContext $context, callable $next): Decision
            {
                $this->seen = $context;

                return $next($context);
            }
        };

        (new TargetResolver([$rule]))->resolve($context);

        self::assertSame($context, $rule->seen);
    }
}
