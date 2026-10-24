<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base class of the persistence integration tests: real SQLite database
 * (built from the migrations in tests/bootstrap.php), one rolled-back
 * transaction per test thanks to DAMA.
 */
abstract class PersistenceTestCase extends KernelTestCase
{
    protected function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    /**
     * Persists, flushes, then detaches everything so the next read hits the database.
     */
    protected function persistAndClear(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->em()->clear();
    }

    protected static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }
}
