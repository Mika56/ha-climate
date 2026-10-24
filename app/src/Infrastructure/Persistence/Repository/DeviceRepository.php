<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Infrastructure\Persistence\Entity\Device;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Device>
 */
final class DeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Device::class);
    }

    public function save(Device $entity): void
    {
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();
    }

    public function remove(Device $entity): void
    {
        $this->getEntityManager()->remove($entity);
        $this->getEntityManager()->flush();
    }

    public function findOneByEntityId(string $entityId): ?Device
    {
        return $this->findOneBy(['entityId' => $entityId]);
    }
}
