<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Engine\Facts;

use App\Application\Engine\Configuration\EngineConfiguration;
use App\Application\Engine\EngineSnapshot;
use App\Application\Engine\Facts\HouseEmptyContributor;
use App\Application\Engine\Facts\RoomVacancyContributor;
use App\Application\Engine\Facts\VacationContributor;
use App\Application\Engine\State\StateStore;
use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\StateContext;
use App\Domain\Climate\Season;
use App\Domain\Decision\GlobalPolicy;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\RoomFacts;
use App\Domain\Decision\VacationPeriod;
use App\Domain\HomeAssistant\EntityId;
use App\Domain\Room\RoomId;
use App\Tests\Unit\Domain\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(HouseEmptyContributor::class)]
#[CoversClass(RoomVacancyContributor::class)]
#[CoversClass(VacationContributor::class)]
#[UsesClass(EngineSnapshot::class)]
#[UsesClass(EngineConfiguration::class)]
#[UsesClass(StateStore::class)]
#[UsesClass(EntityState::class)]
#[UsesClass(StateContext::class)]
#[UsesNamespace('App\Domain')]
final class PresenceContributorsTest extends TestCase
{
    /**
     * @param array<string, array{string, string}> $persons state and last change by person
     * @param list<string>                         $tracked
     */
    #[DataProvider('provideHouses')]
    public function testHouseEmptySince(array $persons, array $tracked, ?string $expected): void
    {
        $states = [];
        foreach ($persons as $entity => [$state, $changed]) {
            $states[] = self::state($entity, $state, $changed);
        }

        $facts = (new HouseEmptyContributor())->contribute(
            Fixtures::room(),
            RoomFacts::none()->withHouseEmptySince(Fixtures::at('2020-01-01 00:00:00')),
            self::snapshot($states, array_map(static fn (string $id): EntityId => new EntityId($id), $tracked)),
        );

        self::assertEquals(null === $expected ? null : Fixtures::at($expected), $facts->houseEmptySince);
    }

    /**
     * @return iterable<string, array{array<string, array{string, string}>, list<string>, string|null}>
     */
    public static function provideHouses(): iterable
    {
        yield 'nobody tracked: feature off' => [[], [], null];
        yield 'someone home' => [['person.a' => ['home', '2026-10-26 06:00:00']], ['person.a'], null];
        yield 'everyone away: the latest departure' => [
            ['person.a' => ['not_home', '2026-10-26 06:00:00'], 'person.b' => ['not_home', '2026-10-26 07:30:00']],
            ['person.a', 'person.b'],
            '2026-10-26 07:30:00',
        ];
        yield 'one away, one home' => [
            ['person.a' => ['not_home', '2026-10-26 06:00:00'], 'person.b' => ['home', '2026-10-26 07:30:00']],
            ['person.a', 'person.b'],
            null,
        ];
        yield 'home after a zone name' => [['person.a' => ['work', '2026-10-26 06:00:00']], ['person.a'], '2026-10-26 06:00:00'];
        yield 'unavailable persons are ignored' => [
            ['person.a' => ['not_home', '2026-10-26 06:00:00'], 'person.b' => ['unavailable', '2026-10-26 09:00:00']],
            ['person.a', 'person.b'],
            '2026-10-26 06:00:00',
        ];
        yield 'unknown and missing persons only' => [['person.a' => ['unknown', '2026-10-26 09:00:00']], ['person.a', 'person.ghost'], null];
        yield 'an unavailable person does not hide someone at home' => [
            ['person.a' => ['unavailable', '2026-10-26 09:00:00'], 'person.b' => ['home', '2026-10-26 07:30:00']],
            ['person.a', 'person.b'],
            null,
        ];
    }

    #[DataProvider('providePresence')]
    public function testRoomVacantSince(?string $state, ?string $expected): void
    {
        $states = null === $state ? [] : [self::state('binary_sensor.living_presence', $state, '2026-10-26 06:45:00')];

        $facts = (new RoomVacancyContributor())->contribute(
            Fixtures::room(),
            RoomFacts::none()->withRoomVacantSince(Fixtures::at('2020-01-01 00:00:00')),
            self::snapshot($states),
        );

        self::assertEquals(null === $expected ? null : Fixtures::at($expected), $facts->roomVacantSince);
    }

    /**
     * @return iterable<string, array{string|null, string|null}>
     */
    public static function providePresence(): iterable
    {
        yield 'off: vacant since the change' => ['off', '2026-10-26 06:45:00'];
        yield 'on: present' => ['on', null];
        yield 'unavailable' => ['unavailable', null];
        yield 'unknown' => ['unknown', null];
        yield 'entity missing' => [null, null];
    }

    public function testARoomWithoutPresenceEntityKeepsTheFacts(): void
    {
        $room = new RoomConfiguration(new RoomId('living'), 'Living', [Fixtures::device()], Fixtures::temperatures(), null, null, null, null, [], 600, 120, 1800);
        $facts = RoomFacts::none();

        self::assertSame($facts, (new RoomVacancyContributor())->contribute($room, $facts, self::snapshot([])));
    }

    public function testTheVacationComesFromTheConfiguration(): void
    {
        $vacation = new VacationPeriod(Fixtures::at('2026-10-26 12:00:00'), Fixtures::at('2026-10-30 18:00:00'), true);

        $with = (new VacationContributor())->contribute(Fixtures::room(), RoomFacts::none(), self::snapshot([], [], $vacation));
        $without = (new VacationContributor())->contribute(Fixtures::room(), RoomFacts::none()->withVacation($vacation), self::snapshot([]));

        self::assertSame($vacation, $with->vacation);
        self::assertNull($without->vacation);
    }

    private static function state(string $entityId, string $state, string $changed): EntityState
    {
        return new EntityState($entityId, $state, [], Fixtures::at($changed), Fixtures::at($changed), new StateContext('c'));
    }

    /**
     * @param list<EntityState> $states
     * @param list<EntityId>    $persons
     */
    private static function snapshot(array $states, array $persons = [], ?VacationPeriod $vacation = null): EngineSnapshot
    {
        $store = new StateStore();
        $store->load($states);

        return new EngineSnapshot(
            Fixtures::at('2026-10-26 08:00:00'),
            new EngineConfiguration([], [], new GlobalPolicy(1800, Fixtures::preset('eco'), Fixtures::preset('away')), Season::Winter, $persons, $vacation, null, 90, 120),
            $store,
        );
    }
}
