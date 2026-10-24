<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Climate\HvacMode;
use App\Infrastructure\Persistence\Entity\HeatingRate;
use App\Infrastructure\Persistence\Entity\Room;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HeatingRate>
 */
final class HeatingRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HeatingRate::class);
    }

    public function save(HeatingRate $entity): void
    {
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();
    }

    public function remove(HeatingRate $entity): void
    {
        $this->getEntityManager()->remove($entity);
        $this->getEntityManager()->flush();
    }

    public function findOneByRoomAndMode(Room $room, HvacMode $mode): ?HeatingRate
    {
        return $this->findOneBy(['room' => $room, 'mode' => $mode]);
    }
}
