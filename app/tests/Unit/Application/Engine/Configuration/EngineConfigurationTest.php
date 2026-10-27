<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Engine\Configuration;

use App\Application\Engine\Configuration\EngineConfiguration;
use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Domain\Decision\GlobalPolicy;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Device\DeviceRole;
use App\Domain\HomeAssistant\EntityId;
use App\Domain\Room\RoomId;
use App\Domain\Schedule\ScheduleId;
use App\Tests\Unit\Domain\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(EngineConfiguration::class)]
#[UsesNamespace('App\Domain')]
final class EngineConfigurationTest extends TestCase
{
    public function testFindsRoomsAndTheRoomOfADevice(): void
    {
        $living = Fixtures::room([Fixtures::device(DeviceRole::Heating, 'climate.living')]);
        $configuration = self::configuration([$living]);

        self::assertSame([$living->id], $configuration->roomIds());
        self::assertSame($living, $configuration->room(new RoomId('living')));
        self::assertNull($configuration->room(new RoomId('other')));
        self::assertSame($living, $configuration->roomOfDevice(new EntityId('climate.living')));
        self::assertNull($configuration->roomOfDevice(new EntityId('climate.other')));
    }

    public function testResolvesTheScheduleOfARoomForAMode(): void
    {
        $heating = Fixtures::weeklySchedule(HvacMode::Heat);
        $room = new RoomConfiguration(
            new RoomId('living'),
            'Living',
            [Fixtures::device()],
            Fixtures::temperatures(),
            $heating->id,
            new ScheduleId('missing'),
            null,
            [],
            [],
            600,
            120,
            1800,
        );
        $configuration = self::configuration([$room], [(string) $heating->id => $heating]);

        self::assertSame($heating, $configuration->scheduleFor($room, HvacMode::Heat));
        self::assertSame($heating, $configuration->schedule($heating->id));
        self::assertNull($configuration->scheduleFor($room, HvacMode::Cool), 'Dangling schedule reference.');
        self::assertNull($configuration->scheduleFor($room, HvacMode::Off));
        self::assertNull($configuration->schedule(null));
    }

    public function testAScheduleOfTheWrongModeIsNotUsed(): void
    {
        $cooling = Fixtures::weeklySchedule(HvacMode::Cool);
        $room = new RoomConfiguration(new RoomId('living'), 'Living', [Fixtures::device()], Fixtures::temperatures(), $cooling->id, null, null, [], [], 600, 120, 1800);

        self::assertNull(self::configuration([$room], [(string) $cooling->id => $cooling])->scheduleFor($room, HvacMode::Heat));
    }

    /**
     * @param list<RoomConfiguration>                            $rooms
     * @param array<string, \App\Domain\Schedule\WeeklySchedule> $schedules
     */
    private static function configuration(array $rooms, array $schedules = []): EngineConfiguration
    {
        return new EngineConfiguration($rooms, $schedules, new GlobalPolicy(1800, Fixtures::preset('eco'), Fixtures::preset('away')), Season::Winter, [], null, null, 90, 120);
    }
}
