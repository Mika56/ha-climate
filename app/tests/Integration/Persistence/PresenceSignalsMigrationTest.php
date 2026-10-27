<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Infrastructure\Persistence\Entity\Room;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The migration that turns the presence sensor of a room into a presence signal, run against a scratch SQLite
 * database on top of the previous schema (the migrations are not autoloaded: they are loaded here by path).
 */
#[CoversClass(Room::class)]
final class PresenceSignalsMigrationTest extends TestCase
{
    private const array BEFORE = ['DoctrineMigrations\Version20261024111536', 'DoctrineMigrations\Version20261025090000'];
    private const string SIGNALS = 'DoctrineMigrations\Version20261027200000';

    private Connection $connection;

    protected function setUp(): void
    {
        foreach (['Version20261024111536', 'Version20261025090000', 'Version20261027200000'] as $version) {
            require_once \dirname(__DIR__, 3).'/migrations/'.$version.'.php';
        }

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (self::BEFORE as $class) {
            $this->migrate($class, 'up');
        }
    }

    public function testThePresenceSensorBecomesOneActiveSignal(): void
    {
        $this->insertRoom('0001', 'Living', 'binary_sensor.living_presence');
        $this->insertRoom('0002', 'Cellar', null);
        $this->insertRoom('0003', 'Blank', '');

        $this->migrate(self::SIGNALS, 'up');

        self::assertSame([
            'Blank' => [],
            'Cellar' => [],
            'Living' => [['entityId' => 'binary_sensor.living_presence', 'kind' => 'active', 'threshold' => null, 'value' => null]],
        ], $this->signals());
        self::assertNotContains('presence_sensor', array_column($this->connection->fetchAllAssociative('PRAGMA table_info(room)'), 'name'));
    }

    public function testDownKeepsTheFirstActiveSignal(): void
    {
        $this->insertRoom('0001', 'Living', null);
        $this->migrate(self::SIGNALS, 'up');
        $this->connection->executeStatement(
            'UPDATE room SET presence_signals = ?',
            ['[{"entityId":"sensor.tv_power","kind":"above","threshold":10.0,"value":null},{"entityId":"light.lamp","kind":"active","threshold":null,"value":null}]'],
        );

        $this->migrate(self::SIGNALS, 'down');

        self::assertSame('light.lamp', $this->connection->fetchOne('SELECT presence_sensor FROM room'));
        self::assertNotContains('presence_signals', array_column($this->connection->fetchAllAssociative('PRAGMA table_info(room)'), 'name'));
    }

    private function insertRoom(string $id, string $name, ?string $presence): void
    {
        $this->connection->executeStatement(
            'INSERT INTO room (id, name, presence_sensor, openings, vacancy_delay_seconds, window_cut_delay_seconds, window_notify_delay_seconds, preheat_enabled, preheat_max_minutes, position) VALUES (x\''.$id.'\', ?, ?, ?, 600, 120, 1800, 0, 120, 0)',
            [$name, $presence, '[]'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function signals(): array
    {
        $signals = [];
        foreach ($this->connection->fetchAllAssociative('SELECT name, presence_signals FROM room') as $row) {
            self::assertIsString($row['name']);
            self::assertIsString($row['presence_signals']);
            $signals[$row['name']] = json_decode($row['presence_signals'], true, flags: \JSON_THROW_ON_ERROR);
        }

        ksort($signals);

        return $signals;
    }

    /**
     * @param 'up'|'down' $direction
     */
    private function migrate(string $class, string $direction): void
    {
        self::assertTrue(class_exists($class));
        $migration = new $class($this->connection, new NullLogger());
        self::assertInstanceOf(AbstractMigration::class, $migration);

        'up' === $direction ? $migration->up(new Schema()) : $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }
}
