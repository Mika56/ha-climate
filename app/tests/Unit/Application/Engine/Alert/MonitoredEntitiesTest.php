<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Engine\Alert;

use App\Application\Engine\Alert\MonitoredEntities;
use App\Application\Engine\Configuration\EngineConfiguration;
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

#[CoversClass(MonitoredEntities::class)]
#[UsesClass(EngineConfiguration::class)]
#[UsesNamespace('App\Domain')]
final class MonitoredEntitiesTest extends TestCase
{
    public function testListsEveryEntityARoomReliesOnWithItsRooms(): void
    {
        $schedule = new WeeklySchedule(new ScheduleId('heat-week'), 'Week', HvacMode::Heat, [
            Fixtures::slot(Weekday::Monday, new AnchoredSlotStart(new EntityId('input_datetime.bedtime'), 0, 1320), 'night'),
            Fixtures::slot(Weekday::Monday, Fixtures::fixed(7), 'comfort'),
        ]);
        $configuration = new EngineConfiguration(
            [self::room('living'), self::room('bedroom')],
            ['heat-week' => $schedule],
            new GlobalPolicy(1800, Fixtures::preset('eco'), Fixtures::preset('away')),
            Season::Winter,
            [new EntityId('person.alice')],
            null,
            new EntityId('weather.home'),
            90,
            120,
        );
        $monitored = new MonitoredEntities();

        $all = $monitored->all($configuration);

        $entities = array_keys($all);
        sort($entities);
        self::assertSame([
            'binary_sensor.bedroom_window',
            'binary_sensor.living_window',
            'climate.bedroom',
            'climate.living',
            'input_datetime.bedtime',
            'sensor.bedroom_temperature',
            'sensor.living_temperature',
        ], $entities);
        self::assertArrayNotHasKey('binary_sensor.living_presence', $all, 'A presence signal must not take a room out of control.');
        self::assertSame(['living'], array_map(static fn (RoomConfiguration $room): string => (string) $room->id, $all['input_datetime.bedtime']));
        self::assertSame(['living'], array_map(static fn (RoomConfiguration $room): string => (string) $room->id, $monitored->roomsUsing($configuration, 'climate.living')));
        self::assertSame([], $monitored->roomsUsing($configuration, 'person.alice'));
        self::assertSame([], $monitored->roomsUsing($configuration, 'weather.home'));
    }

    private static function room(string $id): RoomConfiguration
    {
        $template = Fixtures::room([Fixtures::device(DeviceRole::Heating, 'climate.'.$id)]);

        return new RoomConfiguration(
            new RoomId($id),
            ucfirst($id),
            $template->devices,
            $template->presetTemperatures,
            'living' === $id ? new ScheduleId('heat-week') : null,
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
