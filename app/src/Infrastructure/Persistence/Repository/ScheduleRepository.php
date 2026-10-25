<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Infrastructure\Persistence\Entity\Preset;
use App\Infrastructure\Persistence\Entity\Schedule;
use App\Infrastructure\Persistence\Entity\ScheduleSlot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Schedule>
 */
final class ScheduleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Schedule::class);
    }

    public function save(Schedule $entity): void
    {
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();
    }

    public function remove(Schedule $entity): void
    {
        $this->getEntityManager()->remove($entity);
        $this->getEntityManager()->flush();
    }

    /**
     * @return list<Schedule>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }

    /**
     * How many schedule slots use the preset.
     */
    public function countSlotsUsing(Preset $preset): int
    {
        $count = $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(ScheduleSlot::class, 's')
            ->where('s.preset = :preset')
            ->setParameter('preset', $preset->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }
}
