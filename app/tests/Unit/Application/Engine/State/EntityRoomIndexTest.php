<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Engine\State;

use App\Application\Engine\Configuration\EngineConfiguration;
use App\Application\Engine\State\EntityRoomIndex;
use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Domain\Decision\GlobalPolicy;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Device\DeviceRole;
use App\Domain\HomeAssistant\EntityId;
use App\Domain\Room\PresenceSignal;
use App\Domain\Room\PresenceSignalKind;
use App\Domain\Room\RoomId;
use App\Domain\Schedule\AnchoredSlotStart;
use App\Domain\Schedule\ScheduleId;
use App\Domain\Schedule\Weekday;
use App\Domain\Schedule\WeeklySchedule;
use App\Tests\Unit\Domain\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityRoomIndex::class)]
#[UsesClass(EngineConfiguration::class)]
#[UsesNamespace('App\Domain')]
final class EntityRoomIndexTest extends TestCase
{
    public function testIndexesEveryEntityThatCanAffectARoom(): void
    {
        $index = new EntityRoomIndex();
        $index->rebuild(self::configuration([self::room('living', 'climate.living'), self::room('bedroom', 'climate.bedroom')]));

        self::assertSame('living', (string) $index->roomsFor('climate.living')[0]);
        self::assertSame('living', (string) $index->roomsFor('sensor.living_temperature')[0]);
        self::assertSame('living', (string) $index->roomsFor('binary_sensor.living_presence')[0]);
        self::assertSame('living', (string) $index->roomsFor('binary_sensor.living_window')[0]);
        self::assertSame('bedroom', (string) $index->roomsFor('climate.bedroom')[0]);
        self::assertSame([], $index->roomsFor('climate.unknown'));
        self::assertTrue($index->tracks('climate.living'));
        self::assertFalse($index->tracks('climate.unknown'));
    }

    public function testGlobalEntitiesAffectEveryRoom(): void
    {
        $index = new EntityRoomIndex();
        $index->rebuild(self::configuration(
            [self::room('living', 'climate.living'), self::room('bedroom', 'climate.bedroom')],
            persons: [new EntityId('person.alice')],
            weather: new EntityId('weather.home'),
        ));

        self::assertSame(['living', 'bedroom'], array_map(strval(...), $index->roomsFor('person.alice')));
        self::assertSame(['living', 'bedroom'], array_map(strval(...), $index->roomsFor('weather.home')));
    }

    public function testScheduleAnchorsAffectTheRoomsThatFollowTheSchedule(): void
    {
        $schedule = new WeeklySchedule(new ScheduleId('heat-week'), 'Week', HvacMode::Heat, [
            Fixtures::slot(Weekday::Monday, new AnchoredSlotStart(new EntityId('input_datetime.bedtime'), 0, 1320), 'night'),
            Fixtures::slot(Weekday::Monday, Fixtures::fixed(7), 'comfort'),
        ]);
        $index = new EntityRoomIndex();
        $index->rebuild(self::configuration([self::room('living', 'climate.living')], [$schedule]));

        self::assertSame(['living'], array_map(strval(...), $index->roomsFor('input_datetime.bedtime')));
    }

    public function testEveryPresenceSignalIsIndexed(): void
    {
        $template = self::room('living', 'climate.living');
        $room = new RoomConfiguration(
            $template->id,
            $template->name,
            $template->devices,
            $template->presetTemperatures,
            null,
            null,
            null,
            [
                new PresenceSignal(new EntityId('light.living'), PresenceSignalKind::Active),
                new PresenceSignal(new EntityId('sensor.tv_power'), PresenceSignalKind::Above, 10.0),
                new PresenceSignal(new EntityId('sensor.phone_area'), PresenceSignalKind::Equals, null, 'Living room'),
            ],
            [],
            600,
            120,
            1800,
        );
        $index = new EntityRoomIndex();
        $index->rebuild(self::configuration([$room]));

        self::assertSame(['living'], array_map(strval(...), $index->roomsFor('light.living')));
        self::assertSame(['living'], array_map(strval(...), $index->roomsFor('sensor.tv_power')));
        self::assertSame(['living'], array_map(strval(...), $index->roomsFor('sensor.phone_area')));
    }

    public function testListsTheEntitiesToSubscribeToAndNothingWithoutRooms(): void
    {
        $index = new EntityRoomIndex();
        $index->rebuild(self::configuration([self::room('living', 'climate.living')]));
        self::assertSame([
            'binary_sensor.living_presence',
            'binary_sensor.living_window',
            'climate.living',
            'sensor.living_temperature',
        ], $index->entityIds());

        $index->rebuild(self::configuration([], persons: [new EntityId('person.alice')]));
        self::assertSame([], $index->entityIds());
    }

    /**
     * @param list<RoomConfiguration> $rooms
     * @param list<WeeklySchedule>    $schedules
     * @param list<EntityId>          $persons
     */
    private static function configuration(array $rooms, array $schedules = [], array $persons = [], ?EntityId $weather = null): EngineConfiguration
    {
        $indexed = [];
        foreach ($schedules as $schedule) {
            $indexed[(string) $schedule->id] = $schedule;
        }

        return new EngineConfiguration($rooms, $indexed, new GlobalPolicy(1800, Fixtures::preset('eco'), Fixtures::preset('away')), Season::Winter, $persons, null, $weather, 90, 120);
    }

    private static function room(string $id, string $device): RoomConfiguration
    {
        $template = Fixtures::room([Fixtures::device(DeviceRole::Heating, $device)]);

        return new RoomConfiguration(
            new RoomId($id),
            $template->name,
            $template->devices,
            $template->presetTemperatures,
            new ScheduleId('heat-week'),
            null,
            new EntityId('sensor.'.$id.'_temperature'),
            [new PresenceSignal(new EntityId('binary_sensor.'.$id.'_presence'), PresenceSignalKind::Active)],
            [new EntityId('binary_sensor.'.$id.'_window')],
            600,
            120,
            1800,
        );
    }
}
