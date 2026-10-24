<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Infrastructure\Persistence\Entity\GlobalSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GlobalSettings>
 */
final class GlobalSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GlobalSettings::class);
    }

    public function save(GlobalSettings $entity): void
    {
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();
    }

    public function remove(GlobalSettings $entity): void
    {
        $this->getEntityManager()->remove($entity);
        $this->getEntityManager()->flush();
    }

    /**
     * Returns the singleton settings row, creating it with defaults on first access.
     */
    public function get(): GlobalSettings
    {
        $settings = $this->find(GlobalSettings::SINGLETON_ID);
        if (null === $settings) {
            $settings = new GlobalSettings();
            $this->save($settings);
        }

        return $settings;
    }
}
