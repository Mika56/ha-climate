<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Infrastructure\Persistence\Sqlite\SqlitePragmaDriver;
use App\Infrastructure\Persistence\Sqlite\SqlitePragmaMiddleware;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SqlitePragmaMiddleware::class)]
#[CoversClass(SqlitePragmaDriver::class)]
final class SqlitePragmaTest extends PersistenceTestCase
{
    public function testPragmasAreAppliedToTheApplicationConnection(): void
    {
        self::bootKernel();
        $connection = $this->service(Connection::class);

        self::assertSame(1, $connection->fetchOne('PRAGMA foreign_keys'));
        self::assertSame(5000, $connection->fetchOne('PRAGMA busy_timeout'));
        self::assertSame(1, $connection->fetchOne('PRAGMA synchronous'), 'NORMAL = 1');
        self::assertSame('wal', $connection->fetchOne('PRAGMA journal_mode'));
    }
}
