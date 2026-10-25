<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Infrastructure\Persistence\Entity\AuditEntry;
use App\Infrastructure\Persistence\Entity\Room;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditEntry>
 */
final class AuditEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditEntry::class);
    }

    public function save(AuditEntry $entity): void
    {
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();
    }

    public function remove(AuditEntry $entity): void
    {
        $this->getEntityManager()->remove($entity);
        $this->getEntityManager()->flush();
    }

    /**
     * @return list<AuditEntry> newest first
     */
    public function search(AuditSearchCriteria $criteria): array
    {
        $builder = $this->filtered($this->createQueryBuilder('e')->leftJoin('e.room', 'room')->addSelect('room'), $criteria)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult($criteria->offset)
            ->setMaxResults($criteria->limit);

        /** @var list<AuditEntry> $entries */
        $entries = $builder->getQuery()->getResult();

        return $entries;
    }

    /**
     * The latest entries of the given categories, newest first.
     *
     * @param list<string> $categories
     *
     * @return list<AuditEntry>
     */
    public function latestInCategories(array $categories, int $limit): array
    {
        /** @var list<AuditEntry> $entries */
        $entries = $this->createQueryBuilder('e')
            ->where('e.category IN (:categories)')
            ->setParameter('categories', $categories)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $entries;
    }

    /**
     * Number of entries matching the criteria, ignoring limit and offset.
     */
    public function countMatching(AuditSearchCriteria $criteria): int
    {
        $total = $this->filtered($this->createQueryBuilder('e')->select('COUNT(e.id)'), $criteria)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($total) ? (int) $total : 0;
    }

    /**
     * Deletes entries that occurred strictly before $threshold and returns how many were removed.
     */
    public function purgeOlderThan(\DateTimeImmutable $threshold): int
    {
        $result = $this->createQueryBuilder('e')
            ->delete()
            ->where('e.occurredAt < :threshold')
            ->setParameter('threshold', $threshold, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->execute();

        return $result;
    }

    private function filtered(QueryBuilder $builder, AuditSearchCriteria $criteria): QueryBuilder
    {
        if ($criteria->room instanceof Room) {
            $builder->andWhere('e.room = :room')->setParameter('room', $criteria->room->getId(), 'uuid');
        }
        if (null !== $criteria->actorType) {
            $builder->andWhere('e.actorType = :actorType')->setParameter('actorType', $criteria->actorType);
        }
        if (null !== $criteria->from) {
            $builder->andWhere('e.occurredAt >= :from')->setParameter('from', $criteria->from, Types::DATETIME_IMMUTABLE);
        }
        if (null !== $criteria->to) {
            $builder->andWhere('e.occurredAt <= :to')->setParameter('to', $criteria->to, Types::DATETIME_IMMUTABLE);
        }

        return $builder;
    }
}
