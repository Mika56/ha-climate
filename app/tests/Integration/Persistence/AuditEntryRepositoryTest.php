<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Infrastructure\Persistence\Entity\AuditEntry;
use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Enum\ActorType;
use App\Infrastructure\Persistence\Repository\AuditEntryRepository;
use App\Infrastructure\Persistence\Repository\AuditSearchCriteria;
use App\Infrastructure\Persistence\Repository\RoomRepository;
use App\Infrastructure\Persistence\Sqlite\SqlitePragmaDriver;
use App\Infrastructure\Persistence\Sqlite\SqlitePragmaMiddleware;
use App\Infrastructure\Persistence\Support\Guard;
use App\Infrastructure\Persistence\Type\UtcDateTimeImmutableType;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AuditEntryRepository::class)]
#[CoversClass(AuditSearchCriteria::class)]
#[CoversClass(AuditEntry::class)]
#[CoversClass(Room::class)]
#[CoversClass(RoomRepository::class)]
#[CoversClass(Guard::class)]
#[CoversClass(UtcDateTimeImmutableType::class)]
#[CoversClass(SqlitePragmaMiddleware::class)]
#[CoversClass(SqlitePragmaDriver::class)]
final class AuditEntryRepositoryTest extends PersistenceTestCase
{
    private Room $bedroom;
    private Room $kitchen;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->persistAndClear(new Room('Bedroom'), new Room('Kitchen'));
        $rooms = $this->service(RoomRepository::class)->findAllOrdered();
        self::assertCount(2, $rooms);
        [$a, $b] = $rooms;
        $this->bedroom = 'Bedroom' === $a->getName() ? $a : $b;
        $this->kitchen = 'Kitchen' === $a->getName() ? $a : $b;

        $repository = $this->service(AuditEntryRepository::class);
        $repository->save(new AuditEntry(self::at('2026-03-01 08:00'), ActorType::User, 'config', 'audit.room.renamed', ['from' => 'A', 'to' => 'B'], ['before' => 'A'], 'u1', 'Alice', $this->bedroom));
        $repository->save(new AuditEntry(self::at('2026-03-02 08:00'), ActorType::System, 'decision', 'audit.target.changed', [], [], null, null, $this->bedroom));
        $repository->save(new AuditEntry(self::at('2026-03-03 08:00'), ActorType::Device, 'manual', 'audit.manual.detected', [], [], null, null, $this->kitchen));
        $repository->save(new AuditEntry(self::at('2026-03-04 08:00'), ActorType::Automation, 'season', 'audit.season.changed'));
    }

    public function testSearchWithoutFilterReturnsNewestFirst(): void
    {
        $repository = $this->service(AuditEntryRepository::class);

        $entries = $repository->search(new AuditSearchCriteria());

        self::assertSame(
            ['audit.season.changed', 'audit.manual.detected', 'audit.target.changed', 'audit.room.renamed'],
            array_map(static fn (AuditEntry $e): string => $e->getMessageKey(), $entries),
        );
        self::assertSame(4, $repository->countMatching(new AuditSearchCriteria()));
    }

    public function testSearchFiltersByRoomActorAndPeriod(): void
    {
        $repository = $this->service(AuditEntryRepository::class);

        $byRoom = new AuditSearchCriteria(room: $this->bedroom);
        self::assertCount(2, $repository->search($byRoom));
        self::assertSame(2, $repository->countMatching($byRoom));

        $byActor = new AuditSearchCriteria(actorType: ActorType::Device);
        self::assertSame(['audit.manual.detected'], array_map(static fn (AuditEntry $e): string => $e->getMessageKey(), $repository->search($byActor)));

        $byPeriod = new AuditSearchCriteria(from: self::at('2026-03-02 08:00'), to: self::at('2026-03-03 08:00'));
        self::assertSame(2, $repository->countMatching($byPeriod));

        $combined = new AuditSearchCriteria($this->bedroom, ActorType::System, self::at('2026-03-01 00:00'), self::at('2026-03-31 00:00'));
        self::assertSame(1, $repository->countMatching($combined));
    }

    public function testPaginationDoesNotAffectTheTotal(): void
    {
        $repository = $this->service(AuditEntryRepository::class);
        $criteria = new AuditSearchCriteria(limit: 2, offset: 1);

        $page = $repository->search($criteria);

        self::assertSame(['audit.manual.detected', 'audit.target.changed'], array_map(static fn (AuditEntry $e): string => $e->getMessageKey(), $page));
        self::assertSame(4, $repository->countMatching($criteria));
    }

    public function testEntryKeepsDetailsAndRoomSnapshot(): void
    {
        $repository = $this->service(AuditEntryRepository::class);
        $this->em()->clear();

        $entries = $repository->search(new AuditSearchCriteria(actorType: ActorType::User));

        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame('u1', $entry->getActorId());
        self::assertSame('Alice', $entry->getActorName());
        self::assertSame('config', $entry->getCategory());
        self::assertSame(['from' => 'A', 'to' => 'B'], $entry->getParams());
        self::assertSame(['before' => 'A'], $entry->getPayload());
        self::assertSame('Bedroom', $entry->getRoomName());
        self::assertSame('Bedroom', $entry->getRoom()?->getName());
        self::assertEquals(self::at('2026-03-01 08:00'), $entry->getOccurredAt());
        self::assertSame(ActorType::User, $entry->getActorType());
        self::assertNotEmpty($entry->getId()->toRfc4122());
    }

    public function testDeletingARoomKeepsEntriesWithTheirNameSnapshot(): void
    {
        $rooms = $this->service(RoomRepository::class);
        $room = $rooms->find($this->bedroom->getId());
        self::assertInstanceOf(Room::class, $room);
        $rooms->remove($room);
        $this->em()->clear();

        $entries = $this->service(AuditEntryRepository::class)->search(new AuditSearchCriteria(actorType: ActorType::User));

        self::assertCount(1, $entries);
        self::assertNull($entries[0]->getRoom());
        self::assertSame('Bedroom', $entries[0]->getRoomName());
    }

    public function testPurgeOlderThanDeletesStrictlyOlderEntries(): void
    {
        $repository = $this->service(AuditEntryRepository::class);

        self::assertSame(2, $repository->purgeOlderThan(self::at('2026-03-03 08:00')));
        $this->em()->clear();

        self::assertSame(2, $repository->countMatching(new AuditSearchCriteria()));
        self::assertSame(0, $repository->purgeOlderThan(self::at('2026-01-01 00:00')));
    }

    public function testCriteriaRejectInvalidPagination(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AuditSearchCriteria(limit: 0);
    }
}
