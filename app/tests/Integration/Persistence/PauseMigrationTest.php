<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Infrastructure\Persistence\Entity\Alert;
use App\Infrastructure\Persistence\Entity\GlobalSettings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The migration that turns the season "off" into "winter, paused", run against a scratch SQLite database on top
 * of the initial schema (the migrations are not autoloaded: they are loaded here by path).
 */
#[CoversClass(GlobalSettings::class)]
#[CoversClass(Alert::class)]
final class PauseMigrationTest extends TestCase
{
    private const string INITIAL = 'DoctrineMigrations\Version20261024111536';
    private const string PAUSE = 'DoctrineMigrations\Version20261025090000';

    private Connection $connection;

    protected function setUp(): void
    {
        require_once \dirname(__DIR__, 3).'/migrations/Version20261024111536.php';
        require_once \dirname(__DIR__, 3).'/migrations/Version20261025090000.php';

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->migrate(self::INITIAL, 'up');
    }

    #[DataProvider('seasons')]
    public function testAMigratedInstallationKeepsItsSeasonAndOffBecomesWinterPaused(string $before, string $season, int $paused): void
    {
        $this->insertSettings($before);

        $this->migrate(self::PAUSE, 'up');

        self::assertSame(['season' => $season, 'paused' => $paused], $this->settings());
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function seasons(): iterable
    {
        yield 'off becomes winter + paused' => ['off', 'winter', 1];
        yield 'winter stays active' => ['winter', 'winter', 0];
        yield 'summer stays active' => ['summer', 'summer', 0];
    }

    public function testExistingAlertsGetNoPendingNotification(): void
    {
        $this->connection->executeStatement("INSERT INTO alert (id, alert_key, type, opened_at) VALUES (x'0123', 'entity.sensor.x', 'entity_unavailable', '2026-10-24 10:00:00')");

        $this->migrate(self::PAUSE, 'up');

        self::assertSame(['notification_type' => null, 'notification_params' => null], $this->connection->fetchAssociative('SELECT notification_type, notification_params FROM alert'));
    }

    public function testDownIsBestEffortAndPausedGoesBackToOff(): void
    {
        $this->insertSettings('off');
        $this->migrate(self::PAUSE, 'up');

        $this->migrate(self::PAUSE, 'down');

        self::assertSame(['season' => 'off'], $this->connection->fetchAssociative('SELECT season FROM global_settings'));
        self::assertNotContains('paused', array_column($this->connection->fetchAllAssociative('PRAGMA table_info(global_settings)'), 'name'));
        self::assertNotContains('notification_type', array_column($this->connection->fetchAllAssociative('PRAGMA table_info(alert)'), 'name'));
    }

    private function insertSettings(string $season): void
    {
        $this->connection->executeStatement(
            'INSERT INTO global_settings (id, season, house_empty_delay_seconds, tracked_persons, manual_override_minutes, vacation_enabled, off_during_vacation, audit_retention_days, schema_version) VALUES (1, ?, 3600, ?, 120, 0, 1, 90, 1)',
            [$season, '[]'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        $row = $this->connection->fetchAssociative('SELECT season, paused FROM global_settings');
        self::assertIsArray($row);
        self::assertIsNumeric($row['paused']);

        return ['season' => $row['season'], 'paused' => (int) $row['paused']];
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
