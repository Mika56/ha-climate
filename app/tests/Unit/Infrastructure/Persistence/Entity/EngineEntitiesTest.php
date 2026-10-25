<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\Entity;

use App\Domain\Climate\HvacMode;
use App\Infrastructure\Persistence\Entity\Alert;
use App\Infrastructure\Persistence\Entity\AuditEntry;
use App\Infrastructure\Persistence\Entity\CommandLog;
use App\Infrastructure\Persistence\Entity\HeatingRate;
use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Entity\RoomOverride;
use App\Infrastructure\Persistence\Entity\Timer;
use App\Infrastructure\Persistence\Enum\ActorType;
use App\Infrastructure\Persistence\Support\Guard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Alert::class)]
#[CoversClass(AuditEntry::class)]
#[CoversClass(CommandLog::class)]
#[CoversClass(HeatingRate::class)]
#[CoversClass(RoomOverride::class)]
#[CoversClass(Timer::class)]
#[UsesClass(Room::class)]
#[UsesClass(Guard::class)]
final class EngineEntitiesTest extends TestCase
{
    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    public function testBoostNeedsAnEndOrNextSlot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RoomOverride::boost(new Room('A'), HvacMode::Heat, 1.0, self::at('2026-01-01 10:00'), null, false, 'user');
    }

    public function testOverrideMustEndAfterItStarted(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RoomOverride::manual(new Room('A'), HvacMode::Heat, 20.0, self::at('2026-01-01 10:00'), self::at('2026-01-01 10:00'), 'device');
    }

    public function testOverrideModeCannotBeOff(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RoomOverride::manual(new Room('A'), HvacMode::Off, 20.0, self::at('2026-01-01 10:00'), self::at('2026-01-01 11:00'), 'device');
    }

    public function testOverrideActivity(): void
    {
        $override = RoomOverride::boost(new Room('A'), HvacMode::Heat, 1.0, self::at('2026-01-01 10:00'), null, true, 'user');

        self::assertFalse($override->isActiveAt(self::at('2026-01-01 09:59')));
        self::assertTrue($override->isActiveAt(self::at('2030-01-01 00:00')));
        self::assertNotEmpty($override->getId()->toRfc4122());
    }

    public function testAlertCannotBeResolvedBeforeItOpened(): void
    {
        $alert = new Alert('k', 't', self::at('2026-01-01 10:00'));

        $this->expectException(\InvalidArgumentException::class);
        $alert->resolve(self::at('2026-01-01 09:00'));
    }

    public function testAlertAccessors(): void
    {
        $room = new Room('A');
        $alert = new Alert('k', 't', self::at('2026-01-01 10:00'), 'sensor.x', $room);

        self::assertSame($room, $alert->getRoom());
        self::assertSame('sensor.x', $alert->getEntityId());
        self::assertTrue($alert->isActive());
        self::assertNotEmpty($alert->getId()->toRfc4122());
    }

    public function testAnAlertRemembersWhatItNotifiesWithUntilItIsReopened(): void
    {
        $alert = new Alert('k', 't', self::at('2026-01-01 10:00'));
        self::assertNull($alert->getNotificationType());
        self::assertSame([], $alert->getNotificationParams());

        $alert->rememberNotification('sensor_unavailable', ['entity' => 'sensor.x']);

        self::assertSame('sensor_unavailable', $alert->getNotificationType());
        self::assertSame(['entity' => 'sensor.x'], $alert->getNotificationParams());

        $alert->resolve(self::at('2026-01-01 11:00'));
        $alert->reopen(self::at('2026-01-01 12:00'));

        self::assertNull($alert->getNotificationType());
        self::assertSame([], $alert->getNotificationParams());
    }

    public function testHeatingRateMustBePositive(): void
    {
        $rate = new HeatingRate(new Room('A'), HvacMode::Heat, 1.0, self::at('2026-01-01 10:00'));

        $this->expectException(\InvalidArgumentException::class);
        $rate->learn(0.0, self::at('2026-01-01 11:00'));
    }

    public function testHeatingRateModeCannotBeOff(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HeatingRate(new Room('A'), HvacMode::Off, 1.0, self::at('2026-01-01 10:00'));
    }

    public function testHeatingRateNeedsASample(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HeatingRate(new Room('A'), HvacMode::Heat, 1.0, self::at('2026-01-01 10:00'), 0);
    }

    public function testTimerRequiresKeyAndType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Timer('', 'type', self::at('2026-01-01 10:00'));
    }

    public function testCommandLogRequiresAContextId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CommandLog('climate.a', self::at('2026-01-01 10:00'), 'climate.turn_off', ' ');
    }

    public function testAuditEntrySnapshotsTheRoomName(): void
    {
        $room = new Room('Kitchen');
        $entry = new AuditEntry(self::at('2026-01-01 10:00'), ActorType::System, 'decision', 'audit.x', room: $room);

        self::assertSame('Kitchen', $entry->getRoomName());
        self::assertNull($entry->getActorId());
        self::assertSame([], $entry->getParams());
    }

    public function testAuditEntryRequiresMessageKeyAndCategory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AuditEntry(self::at('2026-01-01 10:00'), ActorType::System, '', 'audit.x');
    }
}
