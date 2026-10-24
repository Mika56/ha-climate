<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Infrastructure\Persistence\Entity\CommandLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CommandLog>
 */
final class CommandLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommandLog::class);
    }

    public function save(CommandLog $entity): void
    {
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();
    }

    public function remove(CommandLog $entity): void
    {
        $this->getEntityManager()->remove($entity);
        $this->getEntityManager()->flush();
    }

    public function findOneByContextId(string $haContextId): ?CommandLog
    {
        return $this->findOneBy(['haContextId' => $haContextId]);
    }

    public function findLastForDevice(string $deviceEntityId): ?CommandLog
    {
        return $this->findOneBy(['deviceEntityId' => $deviceEntityId], ['sentAt' => 'DESC']);
    }

    /**
     * Deletes the commands sent strictly before $threshold and returns how many were removed.
     */
    public function purgeOlderThan(\DateTimeImmutable $threshold): int
    {
        $result = $this->createQueryBuilder('c')
            ->delete()
            ->where('c.sentAt < :threshold')
            ->setParameter('threshold', $threshold, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->execute();

        return $result;
    }
}
