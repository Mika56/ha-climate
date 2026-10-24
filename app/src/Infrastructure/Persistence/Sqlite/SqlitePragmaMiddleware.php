<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sqlite;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Applies the SQLite pragmas required by a file shared between php-fpm and
 * the long-running engine, every time a connection is opened.
 *
 * Registered through the attribute (not a service tag) because the `App\`
 * resource in services.yaml redefines any explicit definition of this class.
 * The high priority keeps it closest to the real driver, so the pragmas are
 * set before outer middlewares (e.g. DAMA in tests) open a transaction.
 */
#[AsMiddleware(priority: 200)]
final class SqlitePragmaMiddleware implements Middleware
{
    /**
     * @var list<string>
     */
    public const array PRAGMAS = [
        'PRAGMA journal_mode=WAL',
        'PRAGMA busy_timeout=5000',
        'PRAGMA foreign_keys=ON',
        'PRAGMA synchronous=NORMAL',
    ];

    public function wrap(Driver $driver): Driver
    {
        return new SqlitePragmaDriver($driver);
    }
}
