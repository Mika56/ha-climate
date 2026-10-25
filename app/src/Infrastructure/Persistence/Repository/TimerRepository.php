<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Infrastructure\Persistence\Entity\Timer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Timer>
 */
final class TimerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Timer::class);
    }

    public function save(Timer $entity): void
    {
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();
    }

    public function remove(Timer $entity): void
    {
        $this->getEntityManager()->remove($entity);
        $this->getEntityManager()->flush();
    }

    /**
     * Timers whose due date is at or before $moment, soonest first.
     *
     * @return list<Timer>
     */
    public function findDueBefore(\DateTimeImmutable $moment): array
    {
        /** @var list<Timer> $timers */
        $timers = $this->createQueryBuilder('t')
            ->where('t.dueAt <= :moment')
            ->setParameter('moment', $moment, Types::DATETIME_IMMUTABLE)
            ->orderBy('t.dueAt', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $timers;
    }

    /**
     * Every persisted timer, soonest first (used to rearm timers at boot).
     *
     * @return list<Timer>
     */
    public function findAllPending(): array
    {
        return $this->findBy([], ['dueAt' => 'ASC']);
    }

    public function findOneByKey(string $key): ?Timer
    {
        return $this->findOneBy(['key' => $key]);
    }

    /**
     * Deletes the timer with this key and returns the number of deleted rows (0 or 1).
     */
    public function deleteByKey(string $key): int
    {
        $result = $this->createQueryBuilder('t')
            ->delete()
            ->where('t.key = :key')
            ->setParameter('key', $key, ParameterType::STRING)
            ->getQuery()
            ->execute();

        return $result;
    }
}
