<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Off" is no longer a season: it becomes the separate "paused" switch. An installation whose season was
 * "off" becomes "winter, paused" (nothing changes for it: it keeps controlling nothing). Alerts also remember
 * what they notify with, so that a notification held back while paused is sent when HA Climate resumes.
 */
final class Version20261025090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the paused switch (season "off" becomes "winter" + paused) and the pending notification of alerts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE global_settings ADD COLUMN paused BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql("UPDATE global_settings SET paused = 1, season = 'winter' WHERE season = 'off'");
        $this->addSql('ALTER TABLE alert ADD COLUMN notification_type VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE alert ADD COLUMN notification_params CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert DROP COLUMN notification_params');
        $this->addSql('ALTER TABLE alert DROP COLUMN notification_type');
        // Best effort: a paused installation goes back to the old "off" season.
        $this->addSql("UPDATE global_settings SET season = 'off' WHERE paused = 1");
        $this->addSql('ALTER TABLE global_settings DROP COLUMN paused');
    }
}
