<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Engine\State;

use App\Application\Engine\State\StateStore;
use App\Application\Engine\State\TimeAnchorResolver;
use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\StateContext;
use App\Domain\HomeAssistant\EntityId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TimeAnchorResolver::class)]
#[UsesClass(StateStore::class)]
#[UsesClass(EntityState::class)]
#[UsesClass(StateContext::class)]
#[UsesClass(EntityId::class)]
final class TimeAnchorResolverTest extends TestCase
{
    /**
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('anchors')]
    public function testReadsTheTimeOfAnInputDatetime(string $state, array $attributes, ?int $expected): void
    {
        $store = new StateStore();
        $store->load([new EntityState('input_datetime.bedtime', $state, $attributes, new \DateTimeImmutable(), new \DateTimeImmutable(), new StateContext('c'))]);

        self::assertSame($expected, (new TimeAnchorResolver($store))->minuteOfDay(new EntityId('input_datetime.bedtime')));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, int|null}>
     */
    public static function anchors(): iterable
    {
        yield 'hour and minute attributes' => ['22:30:00', ['has_time' => true, 'hour' => 22, 'minute' => 30], 1350];
        yield 'date and time helper' => ['2026-10-26 07:15:00', ['has_date' => true, 'has_time' => true, 'hour' => 7, 'minute' => 15], 435];
        yield 'time state only' => ['06:05:00', [], 365];
        yield 'time state without seconds' => ['06:05', [], 365];
        yield 'time state with fractional seconds' => ['06:05:00.5', [], 365];
        yield 'midnight' => ['00:00:00', ['has_time' => true, 'hour' => 0, 'minute' => 0], 0];
        yield 'date only helper' => ['2026-10-26', ['has_date' => true, 'has_time' => false], null];
        yield 'unavailable' => ['unavailable', [], null];
        yield 'unknown' => ['unknown', [], null];
        yield 'out of range attributes' => ['25:61:00', ['has_time' => true, 'hour' => 25, 'minute' => 61], null];
        yield 'out of range state' => ['24:00:00', [], null];
    }

    public function testAnUnknownEntityHasNoTime(): void
    {
        self::assertNull((new TimeAnchorResolver(new StateStore()))->minuteOfDay(new EntityId('input_datetime.bedtime')));
    }
}
