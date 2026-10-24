<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\Sqlite;

use App\Infrastructure\Persistence\Sqlite\SqlitePragmaDriver;
use App\Infrastructure\Persistence\Sqlite\SqlitePragmaMiddleware;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver as PdoSqliteDriver;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqlitePragmaMiddleware::class)]
#[CoversClass(SqlitePragmaDriver::class)]
final class SqlitePragmaMiddlewareTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/ha-climate-pragma-'.bin2hex(random_bytes(4)).'.db';
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($this->file.$suffix)) {
                unlink($this->file.$suffix);
            }
        }
    }

    public function testPragmasAreAppliedOnConnect(): void
    {
        $connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'path' => $this->file],
            (new Configuration())->setMiddlewares([new SqlitePragmaMiddleware()]),
        );

        self::assertSame('wal', $connection->fetchOne('PRAGMA journal_mode'));
        self::assertSame(5000, $connection->fetchOne('PRAGMA busy_timeout'));
        self::assertSame(1, $connection->fetchOne('PRAGMA foreign_keys'));
        self::assertSame(1, $connection->fetchOne('PRAGMA synchronous'));
    }

    public function testWithoutTheMiddlewareForeignKeysAreOff(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->file]);

        self::assertSame(0, $connection->fetchOne('PRAGMA foreign_keys'));
    }

    public function testConnectionsAlreadyInATransactionAreLeftUntouched(): void
    {
        $inner = new class(new PdoSqliteDriver()) extends \Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware {
            public function connect(array $params): \Doctrine\DBAL\Driver\Connection
            {
                $connection = parent::connect($params);
                $connection->beginTransaction();

                return $connection;
            }
        };

        $connection = (new SqlitePragmaDriver($inner))->connect(['driver' => 'pdo_sqlite', 'path' => $this->file]);

        $native = $connection->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $native);
        self::assertTrue($native->inTransaction());
        $statement = $native->query('PRAGMA foreign_keys');
        self::assertNotFalse($statement);
        self::assertSame(0, $statement->fetchColumn());
    }

    public function testNonSqliteDriversAreIgnored(): void
    {
        $driver = new SqlitePragmaDriver(new PdoSqliteDriver());

        // A "mysql" param set must not trigger any PRAGMA (they would be a syntax error there);
        // the underlying SQLite driver is only used to obtain a real connection object.
        $connection = $driver->connect(['driver' => 'pdo_mysql', 'path' => $this->file]);

        $native = $connection->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $native);
        $statement = $native->query('PRAGMA foreign_keys');
        self::assertNotFalse($statement);
        self::assertSame(0, $statement->fetchColumn());
    }
}
