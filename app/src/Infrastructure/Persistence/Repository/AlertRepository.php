<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Infrastructure\Persistence\Entity\Alert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Alert>
 */
final class AlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Alert::class);
    }

    public function save(Alert $entity): void
    {
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();
    }

    public function remove(Alert $entity): void
    {
        $this->getEntityManager()->remove($entity);
        $this->getEntityManager()->flush();
    }

    /**
     * Alerts that are still open (not resolved), oldest first.
     *
     * @return list<Alert>
     */
    public function findActive(): array
    {
        /** @var list<Alert> $alerts */
        $alerts = $this->createQueryBuilder('a')
            ->where('a.resolvedAt IS NULL')
            ->orderBy('a.openedAt', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $alerts;
    }

    public function findOneByKey(string $key): ?Alert
    {
        return $this->findOneBy(['key' => $key]);
    }
}
