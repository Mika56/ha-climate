<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Engine\State;

use App\Application\Engine\State\StateStore;
use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\EntityStateChange;
use App\Application\Port\HomeAssistant\StateContext;
use App\Domain\HomeAssistant\EntityId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StateStore::class)]
#[UsesClass(EntityState::class)]
#[UsesClass(EntityStateChange::class)]
#[UsesClass(StateContext::class)]
#[UsesClass(EntityId::class)]
final class StateStoreTest extends TestCase
{
    public function testAFirstStateIsAChange(): void
    {
        $store = new StateStore();

        self::assertTrue($store->apply(new EntityStateChange('sensor.a', null, self::state('sensor.a', '19.5'))));
        self::assertSame('19.5', $store->get('sensor.a')?->state);
        self::assertSame('19.5', $store->get(new EntityId('sensor.a'))?->state);
        self::assertSame(['sensor.a'], $store->entityIds());
    }

    public function testTheSameStateAgainIsNotAChange(): void
    {
        $store = new StateStore();
        $store->apply(new EntityStateChange('sensor.a', null, self::state('sensor.a', '19.5')));

        // Same state, attributes and context, only the timestamps differ: the initial burst of a subscription.
        $again = self::state('sensor.a', '19.5', lastUpdated: '2026-10-26 08:00:00');

        self::assertFalse($store->apply(new EntityStateChange('sensor.a', null, $again)));
    }

    public function testANewStateAttributeOrContextIsAChange(): void
    {
        $store = new StateStore();
        $store->apply(new EntityStateChange('climate.a', null, self::state('climate.a', 'heat', ['temperature' => 21.0])));

        self::assertTrue($store->apply(new EntityStateChange('climate.a', null, self::state('climate.a', 'off', ['temperature' => 21.0]))));
        self::assertTrue($store->apply(new EntityStateChange('climate.a', null, self::state('climate.a', 'off', ['temperature' => 18.0]))));
        self::assertTrue($store->apply(new EntityStateChange('climate.a', null, self::state('climate.a', 'off', ['temperature' => 18.0], 'other-context'))));
    }

    public function testRemovalIsAChangeOnlyForAKnownEntity(): void
    {
        $store = new StateStore();
        $store->apply(new EntityStateChange('sensor.a', null, self::state('sensor.a', '1')));

        self::assertTrue($store->apply(new EntityStateChange('sensor.a', self::state('sensor.a', '1'), null)));
        self::assertNull($store->get('sensor.a'));
        self::assertFalse($store->apply(new EntityStateChange('sensor.a', self::state('sensor.a', '1'), null)));
    }

    public function testLoadReplacesTheContentAndCanFilter(): void
    {
        $store = new StateStore();
        $store->apply(new EntityStateChange('sensor.old', null, self::state('sensor.old', '1')));

        $store->load(
            [self::state('sensor.a', '1'), self::state('sensor.b', '2'), self::state('light.c', 'on')],
            static fn (string $entityId): bool => str_starts_with($entityId, 'sensor.'),
        );

        self::assertSame(['sensor.a', 'sensor.b'], $store->entityIds());
        self::assertNull($store->get('sensor.old'));

        $store->load([self::state('light.c', 'on')]);
        self::assertSame(['light.c'], $store->entityIds());
    }

    public function testRetainKeepsOnlyTheGivenEntities(): void
    {
        $store = new StateStore();
        $store->load([self::state('sensor.a', '1'), self::state('sensor.b', '2')]);

        $store->retain(['sensor.b', 'sensor.unknown']);

        self::assertSame(['sensor.b'], $store->entityIds());
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function state(string $entityId, string $state, array $attributes = [], string $context = 'ctx-1', string $lastUpdated = '2026-10-26 07:00:00'): EntityState
    {
        return new EntityState(
            $entityId,
            $state,
            $attributes,
            new \DateTimeImmutable('2026-10-26 07:00:00'),
            new \DateTimeImmutable($lastUpdated),
            new StateContext($context),
        );
    }
}
