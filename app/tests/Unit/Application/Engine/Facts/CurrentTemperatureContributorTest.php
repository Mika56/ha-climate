<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Engine\Facts;

use App\Application\Engine\Configuration\EngineConfiguration;
use App\Application\Engine\EngineSnapshot;
use App\Application\Engine\Facts\CurrentTemperatureContributor;
use App\Application\Engine\Facts\RoomFactContributorInterface;
use App\Application\Engine\Facts\RoomFactsBuilder;
use App\Application\Engine\State\StateStore;
use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\StateContext;
use App\Domain\Climate\Season;
use App\Domain\Decision\GlobalPolicy;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\RoomFacts;
use App\Domain\Room\RoomId;
use App\Tests\Unit\Domain\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(CurrentTemperatureContributor::class)]
#[CoversClass(RoomFactsBuilder::class)]
#[UsesClass(EngineSnapshot::class)]
#[UsesClass(EngineConfiguration::class)]
#[UsesClass(StateStore::class)]
#[UsesClass(EntityState::class)]
#[UsesClass(StateContext::class)]
#[UsesNamespace('App\Domain')]
final class CurrentTemperatureContributorTest extends TestCase
{
    #[DataProvider('readings')]
    public function testReadsTheNumericStateOfTheTemperatureSensor(?string $state, ?float $expected): void
    {
        $store = new StateStore();
        if (null !== $state) {
            $store->load([new EntityState('sensor.living_temperature', $state, [], new \DateTimeImmutable(), new \DateTimeImmutable(), new StateContext('c'))]);
        }

        $facts = (new CurrentTemperatureContributor())->contribute(Fixtures::room(), RoomFacts::none(), self::snapshot($store));

        self::assertSame($expected, $facts->currentTemperature?->toFloat());
    }

    /**
     * @return iterable<string, array{string|null, float|null}>
     */
    public static function readings(): iterable
    {
        yield 'a reading' => ['19.46', 19.5];
        yield 'an integer' => ['20', 20.0];
        yield 'unavailable' => ['unavailable', null];
        yield 'unknown' => ['unknown', null];
        yield 'not a number' => ['warm', null];
        yield 'an absurd value' => ['0', null];
        yield 'entity missing from the store' => [null, null];
    }

    public function testARoomWithoutSensorKeepsTheFactsAsTheyAre(): void
    {
        $room = new RoomConfiguration(new RoomId('living'), 'Living', [Fixtures::device()], Fixtures::temperatures(), null, null, null, null, [], 600, 120, 1800);
        $facts = RoomFacts::none();

        self::assertSame($facts, (new CurrentTemperatureContributor())->contribute($room, $facts, self::snapshot(new StateStore())));
    }

    public function testTheBuilderStartsFromNoFactAndAppliesEveryContributorInOrder(): void
    {
        $first = new class implements RoomFactContributorInterface {
            public function contribute(RoomConfiguration $room, RoomFacts $facts, EngineSnapshot $snapshot): RoomFacts
            {
                return $facts->withCurrentTemperature(null)->withHouseEmptySince($snapshot->now);
            }
        };
        $second = new class implements RoomFactContributorInterface {
            public function contribute(RoomConfiguration $room, RoomFacts $facts, EngineSnapshot $snapshot): RoomFacts
            {
                return $facts->withRoomVacantSince(null === $facts->houseEmptySince ? null : $facts->houseEmptySince->modify('+1 minute'));
            }
        };
        $snapshot = self::snapshot(new StateStore());

        $facts = (new RoomFactsBuilder([$first, $second]))->build(Fixtures::room(), $snapshot);

        self::assertSame($snapshot->now, $facts->houseEmptySince);
        self::assertEquals($snapshot->now->modify('+1 minute'), $facts->roomVacantSince);
        self::assertEquals(RoomFacts::none(), (new RoomFactsBuilder([]))->build(Fixtures::room(), $snapshot));
    }

    private static function snapshot(StateStore $store): EngineSnapshot
    {
        return new EngineSnapshot(
            new \DateTimeImmutable('2026-10-26 08:00:00'),
            new EngineConfiguration([], [], new GlobalPolicy(1800, Fixtures::preset('eco'), Fixtures::preset('away')), Season::Winter, [], null, null, 90, 120),
            $store,
        );
    }
}
