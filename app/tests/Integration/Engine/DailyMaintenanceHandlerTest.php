<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Engine\Timer\DailyMaintenanceHandler;
use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Infrastructure\Persistence\Entity\AuditEntry;
use App\Infrastructure\Persistence\Entity\CommandLog;
use App\Infrastructure\Persistence\Entity\RoomOverride;
use App\Infrastructure\Persistence\Entity\Timer;
use App\Infrastructure\Persistence\Enum\ActorType;
use App\Infrastructure\Persistence\Repository\AuditEntryRepository;
use App\Infrastructure\Persistence\Repository\CommandLogRepository;
use App\Infrastructure\Persistence\Repository\RoomOverrideRepository;
use App\Infrastructure\Persistence\Repository\TimerRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;

#[CoversClass(DailyMaintenanceHandler::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure')]
final class DailyMaintenanceHandlerTest extends EngineTestCase
{
    public function testItIsBookedAtThe0330OfHomeAssistantTimeOnTheFirstStart(): void
    {
        $this->setSeason(Season::Winter);
        $this->startEngine();

        $timer = $this->service(TimerRepository::class)->findOneByKey('daily_maintenance');

        self::assertInstanceOf(Timer::class, $timer);
        self::assertSame('daily_maintenance', $timer->getType());
        // Monday 08:00 in Paris: the next 03:30 Paris time is Tuesday, 02:30 UTC.
        self::assertSame('2026-10-27 02:30:00', $timer->getDueAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    public function testARestartKeepsTheBookedNight(): void
    {
        $this->setSeason(Season::Winter);
        $this->startEngine();
        $this->engine()->stop();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->homeAssistant()->withConfig(new \App\Application\Port\HomeAssistant\HomeAssistantConfig('fr', 'Europe/Paris', '°C', '2026.10.0'));
        $this->clock()->sleep(3600);

        $this->startEngine();

        self::assertCount(1, $this->service(TimerRepository::class)->findBy(['key' => 'daily_maintenance']));
        self::assertSame('2026-10-27 02:30:00', $this->service(TimerRepository::class)->findOneByKey('daily_maintenance')?->getDueAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    public function testTheNightlyRunPurgesOldDataAndBooksTheNextNight(): void
    {
        $this->setSeason(Season::Winter);
        $room = $this->createRoom();
        $settings = $this->settings();
        $settings->changeAuditRetentionDays(30);
        $this->em()->flush();
        $now = $this->clock()->now();
        $audit = $this->service(AuditEntryRepository::class);
        $audit->save(new AuditEntry($now->modify('-31 days'), ActorType::System, 'engine', 'audit.connection.lost'));
        $audit->save(new AuditEntry($now->modify('-29 days'), ActorType::System, 'engine', 'audit.connection.restored'));
        $commands = $this->service(CommandLogRepository::class);
        $commands->save(new CommandLog('climate.a', $now->modify('-8 days'), 'climate.set_temperature', 'old-context'));
        $commands->save(new CommandLog('climate.a', $now->modify('-2 days'), 'climate.set_temperature', 'recent-context'));
        $managedRoom = $this->em()->find(\App\Infrastructure\Persistence\Entity\Room::class, $room->getId());
        self::assertNotNull($managedRoom);
        $overrides = $this->service(RoomOverrideRepository::class);
        $overrides->save(RoomOverride::boost($managedRoom, HvacMode::Heat, 2.0, $now->modify('-3 hours'), $now->modify('-1 hour'), false, 'user'));
        $overrides->save(RoomOverride::boost($managedRoom, HvacMode::Heat, 2.0, $now->modify('-1 hour'), $now->modify('+48 hours'), false, 'user'));
        $this->startEngine();

        // 03:30 Paris time on Tuesday.
        $this->timers()->advance(19.5 * 3600);

        self::assertSame('audit.maintenance.done', $this->auditEntries('audit.maintenance.done')[0]->getMessageKey());
        self::assertSame(['audit' => 1, 'commands' => 1, 'overrides' => 1], $this->auditEntries('audit.maintenance.done')[0]->getParams());
        self::assertNull($commands->findOneByContextId('old-context'));
        self::assertNotNull($commands->findOneByContextId('recent-context'));
        $remaining = array_map(static fn (AuditEntry $entry): string => $entry->getMessageKey(), $audit->findAll());
        self::assertNotContains('audit.connection.lost', $remaining);
        self::assertContains('audit.connection.restored', $remaining);

        $next = $this->service(TimerRepository::class)->findOneByKey('daily_maintenance');
        self::assertSame('2026-10-28 02:30:00', $next?->getDueAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    public function testNothingToPurgeIsReportedAsZeroes(): void
    {
        $this->setSeason(Season::Winter);
        $this->startEngine();

        $this->timers()->advance(19.5 * 3600);

        self::assertSame(['audit' => 0, 'commands' => 0, 'overrides' => 0], $this->auditEntries('audit.maintenance.done')[0]->getParams());
    }
}
