<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sqlite;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class SqlitePragmaDriver extends AbstractDriverMiddleware
{
    public function connect(array $params): Connection
    {
        $connection = parent::connect($params);

        $driver = $params['driver'] ?? null;
        if ('pdo_sqlite' !== $driver && 'sqlite3' !== $driver) {
            return $connection;
        }

        // A connection handed back inside a transaction (e.g. shared across kernel
        // reboots by a transaction-wrapping test tool) is already configured, and
        // SQLite refuses to change some pragmas mid-transaction.
        $native = $connection->getNativeConnection();
        if ($native instanceof \PDO && $native->inTransaction()) {
            return $connection;
        }

        foreach (SqlitePragmaMiddleware::PRAGMAS as $pragma) {
            $connection->query($pragma);
        }

        return $connection;
    }
}
