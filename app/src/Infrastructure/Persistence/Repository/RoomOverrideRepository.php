<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Entity\RoomOverride;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RoomOverride>
 */
final class RoomOverrideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoomOverride::class);
    }

    public function save(RoomOverride $entity): void
    {
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();
    }

    public function remove(RoomOverride $entity): void
    {
        $this->getEntityManager()->remove($entity);
        $this->getEntityManager()->flush();
    }

    /**
     * Makes $override the only override of its room: the previous ones, ended or not, are removed in the
     * same transaction.
     */
    public function replaceForRoom(RoomOverride $override): void
    {
        $this->clearRoom($override->getRoom(), false);
        $this->getEntityManager()->persist($override);
        $this->getEntityManager()->flush();
    }

    /**
     * Removes every override of the room and returns them as they were.
     *
     * @return list<RoomOverride>
     */
    public function clearRoom(Room $room, bool $flush = true): array
    {
        /** @var list<RoomOverride> $existing */
        $existing = $this->findBy(['room' => $room]);
        foreach ($existing as $override) {
            $this->getEntityManager()->remove($override);
        }
        if ($flush) {
            $this->getEntityManager()->flush();
        }

        return $existing;
    }

    /**
     * The most recent override of the room still in force at $now.
     */
    public function findActiveForRoom(Room $room, \DateTimeImmutable $now): ?RoomOverride
    {
        /** @var RoomOverride|null $override */
        $override = $this->createQueryBuilder('o')
            ->where('o.room = :room')
            ->andWhere('o.startedAt <= :now')
            ->andWhere('o.endsAt IS NULL OR o.endsAt > :now')
            ->setParameter('room', $room->getId(), 'uuid')
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->orderBy('o.startedAt', \SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $override;
    }

    /**
     * Every override still in force at $now, across rooms (used at engine boot).
     *
     * @return list<RoomOverride>
     */
    public function findAllActive(\DateTimeImmutable $now): array
    {
        /** @var list<RoomOverride> $overrides */
        $overrides = $this->createQueryBuilder('o')
            ->where('o.startedAt <= :now')
            ->andWhere('o.endsAt IS NULL OR o.endsAt > :now')
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->orderBy('o.startedAt', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $overrides;
    }

    /**
     * Deletes overrides that ended at or before $now and returns how many were removed.
     */
    public function purgeEndedBefore(\DateTimeImmutable $now): int
    {
        $result = $this->createQueryBuilder('o')
            ->delete()
            ->where('o.endsAt IS NOT NULL AND o.endsAt <= :now')
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->execute();

        return $result;
    }
}
