<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A room has a list of presence signals (JSON, like its openings) instead of one presence sensor. The sensor
 * of an existing room becomes a single `active` signal, which behaves as before.
 */
final class Version20261027200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace the presence sensor of rooms by a list of presence signals.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE room ADD COLUMN presence_signals CLOB DEFAULT '[]' NOT NULL");
        $this->addSql("UPDATE room SET presence_signals = json_array(json_object('entityId', presence_sensor, 'kind', 'active', 'threshold', NULL, 'value', NULL)) WHERE presence_sensor IS NOT NULL AND presence_sensor <> ''");
        $this->addSql('ALTER TABLE room DROP COLUMN presence_sensor');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE room ADD COLUMN presence_sensor VARCHAR(255) DEFAULT NULL');
        // Best effort: the old model has one sensor, the first `active` signal of the room.
        $this->addSql("UPDATE room SET presence_sensor = (SELECT json_extract(signal.value, '$.entityId') FROM json_each(room.presence_signals) AS signal WHERE json_extract(signal.value, '$.kind') = 'active' LIMIT 1)");
        $this->addSql('ALTER TABLE room DROP COLUMN presence_signals');
    }
}
