<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema: rooms, devices, presets, schedules, settings, notifications, timers, overrides, alerts, audit and command logs.
 */
final class Version20261024111536 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the initial HA Climate schema.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE alert (id BLOB NOT NULL, alert_key VARCHAR(190) NOT NULL, type VARCHAR(64) NOT NULL, entity_id VARCHAR(255) DEFAULT NULL, opened_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, notified_at DATETIME DEFAULT NULL, persistent_notification_id VARCHAR(255) DEFAULT NULL, room_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_17FD46C154177093 FOREIGN KEY (room_id) REFERENCES room (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_alert_resolved_at ON alert (resolved_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_alert_key ON alert (alert_key)');
        $this->addSql('CREATE INDEX IDX_17FD46C154177093 ON alert (room_id)');
        $this->addSql('CREATE TABLE audit_entry (id BLOB NOT NULL, occurred_at DATETIME NOT NULL, actor_type VARCHAR(16) NOT NULL, actor_id VARCHAR(255) DEFAULT NULL, actor_name VARCHAR(255) DEFAULT NULL, room_name VARCHAR(128) DEFAULT NULL, category VARCHAR(64) NOT NULL, message_key VARCHAR(255) NOT NULL, params CLOB NOT NULL, payload CLOB NOT NULL, room_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_2C6AF98754177093 FOREIGN KEY (room_id) REFERENCES room (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_audit_occurred_at ON audit_entry (occurred_at)');
        $this->addSql('CREATE INDEX idx_audit_room_occurred_at ON audit_entry (room_id, occurred_at)');
        $this->addSql('CREATE INDEX IDX_2C6AF98754177093 ON audit_entry (room_id)');
        $this->addSql('CREATE TABLE command_log (id BLOB NOT NULL, device_entity_id VARCHAR(255) NOT NULL, sent_at DATETIME NOT NULL, service VARCHAR(128) NOT NULL, data CLOB NOT NULL, ha_context_id VARCHAR(64) NOT NULL, target_temperature DOUBLE PRECISION DEFAULT NULL, hvac_mode VARCHAR(8) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_command_log_context ON command_log (ha_context_id)');
        $this->addSql('CREATE INDEX idx_command_log_device_sent ON command_log (device_entity_id, sent_at)');
        $this->addSql('CREATE TABLE device (id BLOB NOT NULL, entity_id VARCHAR(255) NOT NULL, role VARCHAR(16) NOT NULL, min_on_seconds INTEGER NOT NULL, min_off_seconds INTEGER NOT NULL, hysteresis DOUBLE PRECISION NOT NULL, room_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_92FB68E54177093 FOREIGN KEY (room_id) REFERENCES room (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_device_entity_id ON device (entity_id)');
        $this->addSql('CREATE INDEX IDX_92FB68E54177093 ON device (room_id)');
        $this->addSql('CREATE TABLE global_settings (id SMALLINT NOT NULL, season VARCHAR(8) NOT NULL, house_empty_delay_seconds INTEGER NOT NULL, tracked_persons CLOB NOT NULL, manual_override_minutes INTEGER NOT NULL, vacation_enabled BOOLEAN NOT NULL, vacation_start_at DATETIME DEFAULT NULL, vacation_return_at DATETIME DEFAULT NULL, off_during_vacation BOOLEAN NOT NULL, weather_entity VARCHAR(255) DEFAULT NULL, ui_locale VARCHAR(8) DEFAULT NULL, notification_locale VARCHAR(8) DEFAULT NULL, audit_retention_days INTEGER NOT NULL, schema_version INTEGER NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE heating_rate (id BLOB NOT NULL, mode VARCHAR(8) NOT NULL, degrees_per_hour DOUBLE PRECISION NOT NULL, samples INTEGER NOT NULL, updated_at DATETIME NOT NULL, room_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_A255108154177093 FOREIGN KEY (room_id) REFERENCES room (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_heating_rate_room_mode ON heating_rate (room_id, mode)');
        $this->addSql('CREATE INDEX IDX_A255108154177093 ON heating_rate (room_id)');
        $this->addSql('CREATE TABLE notification_target (id BLOB NOT NULL, service VARCHAR(255) NOT NULL, enabled BOOLEAN NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_notification_target_service ON notification_target (service)');
        $this->addSql('CREATE TABLE notification_type_setting (id BLOB NOT NULL, type VARCHAR(32) NOT NULL, enabled BOOLEAN NOT NULL, delay_seconds INTEGER DEFAULT NULL, persistent_notification BOOLEAN NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_notification_type ON notification_type_setting (type)');
        $this->addSql('CREATE TABLE preset (id BLOB NOT NULL, preset_key VARCHAR(64) NOT NULL, label VARCHAR(128) NOT NULL, color VARCHAR(7) NOT NULL, position INTEGER NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_preset_key ON preset (preset_key)');
        $this->addSql('CREATE TABLE room (id BLOB NOT NULL, name VARCHAR(128) NOT NULL, ha_area_id VARCHAR(255) DEFAULT NULL, temperature_sensor VARCHAR(255) DEFAULT NULL, presence_sensor VARCHAR(255) DEFAULT NULL, openings CLOB NOT NULL, vacancy_delay_seconds INTEGER NOT NULL, window_cut_delay_seconds INTEGER NOT NULL, window_notify_delay_seconds INTEGER NOT NULL, preheat_enabled BOOLEAN NOT NULL, preheat_max_minutes INTEGER NOT NULL, position INTEGER NOT NULL, heating_schedule_id BLOB DEFAULT NULL, cooling_schedule_id BLOB DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_729F519BC5E966D5 FOREIGN KEY (heating_schedule_id) REFERENCES schedule (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_729F519BA98B6131 FOREIGN KEY (cooling_schedule_id) REFERENCES schedule (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_729F519BC5E966D5 ON room (heating_schedule_id)');
        $this->addSql('CREATE INDEX IDX_729F519BA98B6131 ON room (cooling_schedule_id)');
        $this->addSql('CREATE TABLE room_override (id BLOB NOT NULL, type VARCHAR(16) NOT NULL, delta DOUBLE PRECISION DEFAULT NULL, absolute_target DOUBLE PRECISION DEFAULT NULL, mode VARCHAR(8) NOT NULL, started_at DATETIME NOT NULL, ends_at DATETIME DEFAULT NULL, until_next_slot BOOLEAN NOT NULL, actor VARCHAR(128) NOT NULL, room_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_22E5C62154177093 FOREIGN KEY (room_id) REFERENCES room (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_room_override_room ON room_override (room_id)');
        $this->addSql('CREATE TABLE room_preset_temperature (id BLOB NOT NULL, mode VARCHAR(8) NOT NULL, temperature DOUBLE PRECISION NOT NULL, room_id BLOB NOT NULL, preset_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_68501E4554177093 FOREIGN KEY (room_id) REFERENCES room (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_68501E4580688E6F FOREIGN KEY (preset_id) REFERENCES preset (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_room_preset_mode ON room_preset_temperature (room_id, preset_id, mode)');
        $this->addSql('CREATE INDEX IDX_68501E4554177093 ON room_preset_temperature (room_id)');
        $this->addSql('CREATE INDEX IDX_68501E4580688E6F ON room_preset_temperature (preset_id)');
        $this->addSql('CREATE TABLE schedule (id BLOB NOT NULL, name VARCHAR(128) NOT NULL, mode VARCHAR(8) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE schedule_slot (id BLOB NOT NULL, weekday SMALLINT NOT NULL, start_type VARCHAR(16) NOT NULL, minute_of_day SMALLINT NOT NULL, anchor_entity_id VARCHAR(255) DEFAULT NULL, offset_minutes SMALLINT NOT NULL, fallback_minute_of_day SMALLINT NOT NULL, schedule_id BLOB NOT NULL, preset_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_4C46003CA40BC2D5 FOREIGN KEY (schedule_id) REFERENCES schedule (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_4C46003C80688E6F FOREIGN KEY (preset_id) REFERENCES preset (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_4C46003CA40BC2D5 ON schedule_slot (schedule_id)');
        $this->addSql('CREATE INDEX IDX_4C46003C80688E6F ON schedule_slot (preset_id)');
        $this->addSql('CREATE TABLE timer (id BLOB NOT NULL, timer_key VARCHAR(190) NOT NULL, type VARCHAR(64) NOT NULL, room_id BLOB DEFAULT NULL, due_at DATETIME NOT NULL, payload CLOB NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_timer_due_at ON timer (due_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_timer_key ON timer (timer_key)');
        $this->addSql('CREATE TABLE user_preference (id BLOB NOT NULL, ha_user_id VARCHAR(64) NOT NULL, locale VARCHAR(8) DEFAULT NULL, theme VARCHAR(8) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_preference_ha_user ON user_preference (ha_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE alert');
        $this->addSql('DROP TABLE audit_entry');
        $this->addSql('DROP TABLE command_log');
        $this->addSql('DROP TABLE heating_rate');
        $this->addSql('DROP TABLE room_override');
        $this->addSql('DROP TABLE room_preset_temperature');
        $this->addSql('DROP TABLE schedule_slot');
        $this->addSql('DROP TABLE device');
        $this->addSql('DROP TABLE notification_target');
        $this->addSql('DROP TABLE notification_type_setting');
        $this->addSql('DROP TABLE timer');
        $this->addSql('DROP TABLE user_preference');
        $this->addSql('DROP TABLE global_settings');
        $this->addSql('DROP TABLE room');
        $this->addSql('DROP TABLE preset');
        $this->addSql('DROP TABLE schedule');
    }
}
