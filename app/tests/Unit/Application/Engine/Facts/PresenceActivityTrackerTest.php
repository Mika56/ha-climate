<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Engine\Facts;

use App\Application\Engine\Configuration\EngineConfiguration;
use App\Application\Engine\Facts\PresenceActivityTracker;
use App\Domain\Climate\Season;
use App\Domain\Decision\GlobalPolicy;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\HomeAssistant\EntityId;
use App\Domain\Room\PresenceSignal;
use App\Domain\Room\PresenceSignalKind;
use App\Domain\Room\RoomId;
use App\Tests\Unit\Domain\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;

#[CoversClass(PresenceActivityTracker::class)]
#[UsesClass(EngineConfiguration::class)]
#[UsesNamespace('App\Domain')]
final class PresenceActivityTrackerTest extends TestCase
{
    public function testTheFirstInactiveObservationIsKeptUntilTheSignalIsActiveAgain(): void
    {
        $tracker = new PresenceActivityTracker();
        $room = new RoomId('living');
        $signal = new PresenceSignal(new EntityId('sensor.tv_power'), PresenceSignalKind::Above, 10.0);

        self::assertEquals(Fixtures::at('2026-10-26 06:00:00'), $tracker->observe($room, $signal, '2', Fixtures::at('2026-10-26 06:00:00')));
        self::assertEquals(Fixtures::at('2026-10-26 06:00:00'), $tracker->observe($room, $signal, '3', Fixtures::at('2026-10-26 06:05:00')));
        self::assertNull($tracker->observe($room, $signal, '50', Fixtures::at('2026-10-26 06:10:00')));
        self::assertEquals(Fixtures::at('2026-10-26 06:20:00'), $tracker->observe($room, $signal, '2', Fixtures::at('2026-10-26 06:20:00')));
    }

    public function testEachRoomAndEachRuleHasItsOwnClock(): void
    {
        $tracker = new PresenceActivityTracker();
        $entity = new EntityId('sensor.tv_power');
        $low = new PresenceSignal($entity, PresenceSignalKind::Above, 10.0);
        $high = new PresenceSignal($entity, PresenceSignalKind::Above, 100.0);

        $tracker->observe(new RoomId('a'), $low, '2', Fixtures::at('2026-10-26 06:00:00'));

        self::assertEquals(Fixtures::at('2026-10-26 07:00:00'), $tracker->observe(new RoomId('b'), $low, '2', Fixtures::at('2026-10-26 07:00:00')));
        self::assertEquals(Fixtures::at('2026-10-26 07:00:00'), $tracker->observe(new RoomId('a'), $high, '2', Fixtures::at('2026-10-26 07:00:00')));
    }

    public function testRetainForgetsTheSignalsThatAreNoLongerConfigured(): void
    {
        $tracker = new PresenceActivityTracker();
        $room = Fixtures::room();
        $kept = $room->presenceSignals[0];
        $removed = new PresenceSignal(new EntityId('sensor.tv_power'), PresenceSignalKind::Above, 10.0);
        $tracker->observe($room->id, $kept, 'off', Fixtures::at('2026-10-26 06:00:00'));
        $tracker->observe($room->id, $removed, '2', Fixtures::at('2026-10-26 06:00:00'));

        $tracker->retain(self::configuration($room));

        self::assertEquals(Fixtures::at('2026-10-26 06:00:00'), $tracker->observe($room->id, $kept, 'off', Fixtures::at('2026-10-26 09:00:00')));
        self::assertEquals(Fixtures::at('2026-10-26 09:00:00'), $tracker->observe($room->id, $removed, '2', Fixtures::at('2026-10-26 09:00:00')));
    }

    private static function configuration(RoomConfiguration $room): EngineConfiguration
    {
        return new EngineConfiguration([$room], [], new GlobalPolicy(1800, Fixtures::preset('eco'), Fixtures::preset('away')), Season::Winter, [], null, null, 90, 120);
    }
}
