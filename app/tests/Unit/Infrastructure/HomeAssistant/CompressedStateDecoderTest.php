<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\HomeAssistant;

use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\EntityStateChange;
use App\Application\Port\HomeAssistant\InvalidPayloadException;
use App\Application\Port\HomeAssistant\StateContext;
use App\Infrastructure\HomeAssistant\CompressedStateDecoder;
use App\Infrastructure\HomeAssistant\DecodedStateUpdate;
use App\Infrastructure\HomeAssistant\Payload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompressedStateDecoder::class)]
#[CoversClass(DecodedStateUpdate::class)]
#[CoversClass(Payload::class)]
#[UsesClass(EntityState::class)]
#[UsesClass(EntityStateChange::class)]
#[UsesClass(StateContext::class)]
final class CompressedStateDecoderTest extends TestCase
{
    private CompressedStateDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new CompressedStateDecoder();
    }

    public function testInitialStatesWithStringContext(): void
    {
        $update = $this->decoder->decode([
            'a' => [
                'climate.living' => [
                    's' => 'heat',
                    'a' => ['temperature' => 19.5],
                    'c' => 'ctx-1',
                    'lc' => 1700000000.25,
                    'lu' => 1700000005.5,
                ],
            ],
        ], []);

        self::assertCount(1, $update->changes);
        $change = $update->changes[0];
        self::assertSame('climate.living', $change->entityId);
        self::assertNull($change->old);
        self::assertNotNull($change->new);
        self::assertFalse($change->isRemoval());
        self::assertSame('heat', $change->new->state);
        self::assertSame(['temperature' => 19.5], $change->new->attributes);
        self::assertSame('ctx-1', $change->new->context->id);
        self::assertNull($change->new->context->parentId);
        self::assertNull($change->new->context->userId);
        self::assertSame('1700000000.250000', $change->new->lastChanged->format('U.u'));
        self::assertSame('1700000005.500000', $change->new->lastUpdated->format('U.u'));
        self::assertSame($change->new, $update->cache['climate.living']);
    }

    public function testInitialStateWithObjectContextAndMissingLastUpdated(): void
    {
        $update = $this->decoder->decode([
            'a' => [
                'sensor.t' => [
                    's' => '20',
                    'c' => ['id' => 'ctx', 'parent_id' => 'parent', 'user_id' => 'user'],
                    'lc' => 1700000000.0,
                ],
            ],
        ], []);

        $new = $update->changes[0]->new;
        self::assertNotNull($new);
        self::assertSame([], $new->attributes);
        self::assertEquals(new StateContext('ctx', 'parent', 'user'), $new->context);
        self::assertSame($new->lastChanged->format('U.u'), $new->lastUpdated->format('U.u'));
    }

    public function testInitialStateWithOnlyLastUpdatedUsesItForBoth(): void
    {
        $update = $this->decoder->decode(['a' => ['sensor.t' => ['s' => '1', 'c' => 'x', 'lu' => 1700000001.0]]], []);

        $new = $update->changes[0]->new;
        self::assertNotNull($new);
        self::assertSame('1700000001.000000', $new->lastChanged->format('U.u'));
    }

    public function testStateWithoutAnyTimestampIsRejected(): void
    {
        $this->expectException(InvalidPayloadException::class);

        $this->decoder->decode(['a' => ['sensor.t' => ['s' => '1', 'c' => 'x']]], []);
    }

    public function testDiffChangesStateAttributesContextAndTimestamps(): void
    {
        $cache = $this->cacheWith(new EntityState(
            'climate.living',
            'heat',
            ['temperature' => 19.5, 'hvac_action' => 'heating', 'friendly_name' => 'Living'],
            new \DateTimeImmutable('@1700000000'),
            new \DateTimeImmutable('@1700000000'),
            new StateContext('ctx-1'),
        ));

        $update = $this->decoder->decode(['c' => ['climate.living' => [
            '+' => [
                's' => 'cool',
                'a' => ['temperature' => 24, 'new_attr' => true],
                'c' => ['id' => 'ctx-2', 'user_id' => 'u'],
                'lc' => 1700000100.0,
            ],
            '-' => ['a' => ['hvac_action', 'does_not_exist']],
        ]]], $cache);

        self::assertCount(1, $update->changes);
        $change = $update->changes[0];
        self::assertSame($cache['climate.living'], $change->old);
        $new = $change->new;
        self::assertNotNull($new);
        self::assertSame('cool', $new->state);
        self::assertSame(['temperature' => 24, 'friendly_name' => 'Living', 'new_attr' => true], $new->attributes);
        self::assertEquals(new StateContext('ctx-2', null, 'u'), $new->context);
        self::assertSame('1700000100', $new->lastChanged->format('U'));
        self::assertSame('1700000100', $new->lastUpdated->format('U'), 'lu absent means same as lc');
        self::assertSame($new, $update->cache['climate.living']);
    }

    public function testDiffWithOnlyLastUpdatedKeepsLastChanged(): void
    {
        $cache = $this->cacheWith($this->state('sensor.t', '1'));

        $update = $this->decoder->decode(['c' => ['sensor.t' => ['+' => ['lu' => 1700000050.0, 'c' => 'ctx-9']]]], $cache);

        $new = $update->changes[0]->new;
        self::assertNotNull($new);
        self::assertSame('1700000000', $new->lastChanged->format('U'));
        self::assertSame('1700000050', $new->lastUpdated->format('U'));
        self::assertSame('ctx-9', $new->context->id);
        self::assertSame('1', $new->state);
    }

    public function testAContextInADiffOnlyReplacesWhatItCarries(): void
    {
        $previous = new EntityState('sensor.t', '1', [], new \DateTimeImmutable('@1700000000'), new \DateTimeImmutable('@1700000000'), new StateContext('c1', 'p1', 'u1'));
        $cache = $this->cacheWith($previous);

        $bareId = $this->decoder->decode(['c' => ['sensor.t' => ['+' => ['c' => 'c2']]]], $cache)->cache['sensor.t']->context;
        $onlyUser = $this->decoder->decode(['c' => ['sensor.t' => ['+' => ['c' => ['user_id' => 'u2']]]]], $cache)->cache['sensor.t']->context;
        $parentCleared = $this->decoder->decode(['c' => ['sensor.t' => ['+' => ['c' => ['id' => 'c3', 'parent_id' => null]]]]], $cache)->cache['sensor.t']->context;

        self::assertEquals(new StateContext('c2', 'p1', 'u1'), $bareId);
        self::assertEquals(new StateContext('c1', 'p1', 'u2'), $onlyUser);
        self::assertEquals(new StateContext('c3', null, 'u1'), $parentCleared);
    }

    public function testDiffWithOnlyRemovedAttributesAndMalformedKeys(): void
    {
        $cache = $this->cacheWith($this->state('sensor.t', '1', ['a' => 1, 'b' => 2]));

        $update = $this->decoder->decode(['c' => ['sensor.t' => ['-' => ['a' => ['a', 12]]]]], $cache);

        $new = $update->changes[0]->new;
        self::assertNotNull($new);
        self::assertSame(['b' => 2], $new->attributes);
    }

    public function testDiffForUnknownEntityIsSkipped(): void
    {
        $update = $this->decoder->decode(['c' => ['sensor.ghost' => ['+' => ['s' => '1']]]], []);

        self::assertSame([], $update->changes);
        self::assertSame([], $update->cache);
    }

    public function testRemoval(): void
    {
        $existing = $this->state('sensor.t', '1');

        $update = $this->decoder->decode(['r' => ['sensor.t', 'sensor.unknown']], $this->cacheWith($existing));

        self::assertCount(1, $update->changes);
        self::assertSame($existing, $update->changes[0]->old);
        self::assertNull($update->changes[0]->new);
        self::assertTrue($update->changes[0]->isRemoval());
        self::assertSame([], $update->cache);
    }

    public function testRemovalWithNonStringIdIsRejected(): void
    {
        $this->expectException(InvalidPayloadException::class);

        $this->decoder->decode(['r' => [12]], []);
    }

    public function testUnknownKeysAreIgnored(): void
    {
        $update = $this->decoder->decode(['x' => ['whatever'], 'a' => []], []);

        self::assertSame([], $update->changes);
    }

    public function testAddedEntityIdenticalToCachedOneIsNotReported(): void
    {
        $cache = $this->cacheWith($this->state('sensor.t', '1'));

        $update = $this->decoder->decode(['a' => ['sensor.t' => ['s' => '1', 'a' => [], 'c' => 'ctx', 'lc' => 1700000000.0]]], $cache);

        self::assertSame([], $update->changes);
    }

    public function testResubscriptionReportsChangesAndMissingEntitiesAsRemoved(): void
    {
        $cache = $this->cacheWith(
            $this->state('sensor.same', '1'),
            $this->state('sensor.changed', '1'),
            $this->state('sensor.gone', '1'),
        );

        $update = $this->decoder->decode(['a' => [
            'sensor.same' => ['s' => '1', 'a' => [], 'c' => 'ctx', 'lc' => 1700000000.0],
            'sensor.changed' => ['s' => '2', 'a' => [], 'c' => 'ctx', 'lc' => 1700000000.0],
            'sensor.fresh' => ['s' => '3', 'a' => [], 'c' => 'ctx', 'lc' => 1700000000.0],
        ]], $cache, true);

        $byEntity = [];
        foreach ($update->changes as $change) {
            $byEntity[$change->entityId] = $change;
        }
        self::assertSame(['sensor.changed', 'sensor.fresh', 'sensor.gone'], array_keys($byEntity));
        self::assertSame('1', $byEntity['sensor.changed']->old?->state);
        self::assertSame('2', $byEntity['sensor.changed']->new?->state);
        self::assertNull($byEntity['sensor.fresh']->old);
        self::assertTrue($byEntity['sensor.gone']->isRemoval());
        self::assertSame(['sensor.same', 'sensor.changed', 'sensor.fresh'], array_keys($update->cache));
    }

    public function testNonInitialEventKeepsEntitiesAbsentFromAdditions(): void
    {
        $cache = $this->cacheWith($this->state('sensor.kept', '1'));

        $update = $this->decoder->decode(['a' => ['sensor.new' => ['s' => '1', 'c' => 'c', 'lc' => 1700000000.0]]], $cache);

        self::assertArrayHasKey('sensor.kept', $update->cache);
        self::assertCount(1, $update->changes);
    }

    public function testFromStateObjectParsesIsoDatesAndContext(): void
    {
        $state = CompressedStateDecoder::fromStateObject([
            'entity_id' => 'sensor.t',
            'state' => '20.5',
            'attributes' => ['unit' => '°C'],
            'last_changed' => '2024-05-01T10:00:00.123456+00:00',
            'last_updated' => '2024-05-01T10:00:05+00:00',
            'context' => ['id' => 'c1', 'parent_id' => null, 'user_id' => 'u1'],
        ]);

        self::assertSame('sensor.t', $state->entityId);
        self::assertSame('20.5', $state->state);
        self::assertSame(['unit' => '°C'], $state->attributes);
        self::assertSame('2024-05-01T10:00:00.123456+00:00', $state->lastChanged->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('10:00:05', $state->lastUpdated->format('H:i:s'));
        self::assertEquals(new StateContext('c1', null, 'u1'), $state->context);
    }

    public function testFromStateObjectDefaultsLastUpdatedToLastChanged(): void
    {
        $state = CompressedStateDecoder::fromStateObject([
            'entity_id' => 'sensor.t',
            'state' => 'on',
            'last_changed' => '2024-05-01T10:00:00+00:00',
            'context' => 'ctx',
        ]);

        self::assertSame($state->lastChanged->format('c'), $state->lastUpdated->format('c'));
        self::assertSame([], $state->attributes);
    }

    public function testFromStateObjectRejectsInvalidDate(): void
    {
        $this->expectException(InvalidPayloadException::class);

        CompressedStateDecoder::fromStateObject(['entity_id' => 'a.b', 'state' => 'on', 'last_changed' => 'not a date', 'context' => 'c']);
    }

    public function testInvalidContextIsRejected(): void
    {
        $this->expectException(InvalidPayloadException::class);

        CompressedStateDecoder::parseContext(42);
    }

    public function testInvalidTimestampIsRejected(): void
    {
        $this->expectException(InvalidPayloadException::class);

        CompressedStateDecoder::fromEpoch(\NAN);
    }

    public function testPayloadHelpers(): void
    {
        self::assertSame(['a' => 1], Payload::decodeObject('{"a":1}'));
        self::assertSame('{"a":1.0,"b":[]}', Payload::encode(['a' => 1.0, 'b' => []]));
        self::assertSame([1, 2], Payload::list([3 => 1, 7 => 2], 'x'));
        self::assertSame(1.0, Payload::optionalFloat(['f' => 1], 'f'));
        self::assertNull(Payload::optionalFloat([], 'f'));
        self::assertNull(Payload::optionalString([], 's'));
        self::assertSame([], Payload::optionalMap(['m' => null], 'm'));
    }

    /**
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function provideInvalidPayloads(): iterable
    {
        yield 'invalid json' => [static fn (): mixed => Payload::decodeObject('{')];
        yield 'json scalar' => [static fn (): mixed => Payload::decodeObject('12')];
        yield 'unencodable' => [static fn (): mixed => Payload::encode(['a' => \NAN])];
        yield 'list expected' => [static fn (): mixed => Payload::list('x', 'x')];
        yield 'string expected' => [static fn (): mixed => Payload::string(['s' => 1], 's')];
        yield 'optional string wrong type' => [static fn (): mixed => Payload::optionalString(['s' => 1], 's')];
        yield 'float wrong type' => [static fn (): mixed => Payload::optionalFloat(['f' => 'x'], 'f')];
    }

    /**
     * @param \Closure(): mixed $call
     */
    #[DataProvider('provideInvalidPayloads')]
    public function testInvalidPayloadsAreRejected(\Closure $call): void
    {
        $this->expectException(InvalidPayloadException::class);

        $call();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function state(string $entityId, string $state, array $attributes = []): EntityState
    {
        return new EntityState(
            $entityId,
            $state,
            $attributes,
            new \DateTimeImmutable('@1700000000'),
            new \DateTimeImmutable('@1700000000'),
            new StateContext('ctx'),
        );
    }

    /**
     * @return array<string, EntityState>
     */
    private function cacheWith(EntityState ...$states): array
    {
        $cache = [];
        foreach ($states as $state) {
            $cache[$state->entityId] = $state;
        }

        return $cache;
    }
}
