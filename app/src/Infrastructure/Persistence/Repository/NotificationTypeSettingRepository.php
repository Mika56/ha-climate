<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Infrastructure\Persistence\Entity\NotificationTypeSetting;
use App\Infrastructure\Persistence\Enum\NotificationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationTypeSetting>
 */
final class NotificationTypeSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationTypeSetting::class);
    }

    public function save(NotificationTypeSetting $entity): void
    {
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();
    }

    public function remove(NotificationTypeSetting $entity): void
    {
        $this->getEntityManager()->remove($entity);
        $this->getEntityManager()->flush();
    }

    public function findOneByType(NotificationType $type): ?NotificationTypeSetting
    {
        return $this->findOneBy(['type' => $type]);
    }
}
