<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Infrastructure\Persistence\Entity\NotificationTarget;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationTarget>
 */
final class NotificationTargetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationTarget::class);
    }

    public function save(NotificationTarget $entity): void
    {
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();
    }

    public function remove(NotificationTarget $entity): void
    {
        $this->getEntityManager()->remove($entity);
        $this->getEntityManager()->flush();
    }

    /**
     * @return list<NotificationTarget>
     */
    public function findEnabled(): array
    {
        return $this->findBy(['enabled' => true], ['service' => 'ASC']);
    }

    public function findOneByService(string $service): ?NotificationTarget
    {
        return $this->findOneBy(['service' => $service]);
    }
}
