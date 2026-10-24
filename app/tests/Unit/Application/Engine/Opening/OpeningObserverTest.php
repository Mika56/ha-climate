<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Engine\Opening;

use App\Application\Engine\Configuration\EngineConfiguration;
use App\Application\Engine\EngineSnapshot;
use App\Application\Engine\Facts\OpeningContributor;
use App\Application\Engine\Opening\OpeningObserver;
use App\Application\Engine\State\StateStore;
use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\StateContext;
use App\Domain\Climate\Season;
use App\Domain\Decision\GlobalPolicy;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\RoomFacts;
use App\Domain\HomeAssistant\EntityId;
use App\Domain\Room\RoomId;
use App\Tests\Unit\Domain\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpeningObserver::class)]
#[CoversClass(OpeningContributor::class)]
#[UsesClass(EngineSnapshot::class)]
#[UsesClass(EngineConfiguration::class)]
#[UsesClass(StateStore::class)]
#[UsesClass(EntityState::class)]
#[UsesClass(StateContext::class)]
#[UsesNamespace('App\Domain')]
final class OpeningObserverTest extends TestCase
{
    /**
     * @param array<string, array{string, string}> $states entity id => [state, last changed]
     */
    #[DataProvider('situations')]
    public function testTellsSinceWhenTheRoomHasAnOpeningOpen(array $states, ?string $expected): void
    {
        $store = new StateStore();
        $store->load(array_map(
            static fn (string $entityId, array $state): EntityState => new EntityState($entityId, $state[0], [], new \DateTimeImmutable($state[1]), new \DateTimeImmutable($state[1]), new StateContext('c')),
            array_keys($states),
            $states,
        ));

        $since = (new OpeningObserver())->openSince(self::room(), $store);

        self::assertSame($expected, $since?->format('H:i'));
    }

    /**
     * @return iterable<string, array{array<string, array{string, string}>, string|null}>
     */
    public static function situations(): iterable
    {
        yield 'all closed' => [['binary_sensor.window' => ['off', '2026-10-26 07:00'], 'binary_sensor.door' => ['off', '2026-10-26 07:05']], null];
        yield 'one on' => [['binary_sensor.window' => ['on', '2026-10-26 07:10'], 'binary_sensor.door' => ['off', '2026-10-26 07:05']], '07:10'];
        yield 'the earliest of several open ones' => [['binary_sensor.window' => ['on', '2026-10-26 07:10'], 'binary_sensor.door' => ['open', '2026-10-26 07:03']], '07:03'];
        yield 'the later of several open ones does not move it' => [['binary_sensor.window' => ['on', '2026-10-26 07:03'], 'binary_sensor.door' => ['open', '2026-10-26 07:10']], '07:03'];
        yield 'unavailable ones are ignored' => [['binary_sensor.window' => ['unavailable', '2026-10-26 07:00'], 'binary_sensor.door' => ['on', '2026-10-26 07:10']], '07:10'];
        yield 'unknown ones are ignored' => [['binary_sensor.window' => ['unknown', '2026-10-26 07:00']], null];
        yield 'entities not in the store' => [[], null];
    }

    public function testTheContributorFillsTheFactAndClearsItWhenClosed(): void
    {
        $store = new StateStore();
        $store->load([new EntityState('binary_sensor.window', 'on', [], new \DateTimeImmutable('2026-10-26 07:10'), new \DateTimeImmutable('2026-10-26 07:10'), new StateContext('c'))]);
        $snapshot = new EngineSnapshot(
            new \DateTimeImmutable('2026-10-26 08:00:00'),
            new EngineConfiguration([], [], new GlobalPolicy(1800, Fixtures::preset('eco'), Fixtures::preset('away')), Season::Winter, [], null, null, 90, 120),
            $store,
        );
        $contributor = new OpeningContributor(new OpeningObserver());

        $open = $contributor->contribute(self::room(), RoomFacts::none(), $snapshot);
        self::assertSame('07:10', $open->openingOpenSince?->format('H:i'));

        $store->load([]);
        self::assertNull($contributor->contribute(self::room(), $open, $snapshot)->openingOpenSince);
    }

    private static function room(): RoomConfiguration
    {
        $template = Fixtures::room();

        return new RoomConfiguration(
            new RoomId('living'),
            $template->name,
            $template->devices,
            $template->presetTemperatures,
            null,
            null,
            null,
            null,
            [new EntityId('binary_sensor.window'), new EntityId('binary_sensor.door')],
            600,
            120,
            1800,
        );
    }
}
