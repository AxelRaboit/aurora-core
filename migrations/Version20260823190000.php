<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Renames the event offsets from reminders to alerts, to make room for reminders.
 *
 * Apple Calendar's vocabulary, because that is the product being followed: an
 * **alert** is "tell me 30 minutes before this event", a **reminder** is a thing
 * you tick off. They were both called reminders here, and a form with a
 * "Reminders" section that has nothing to do with the "Reminder" you can create
 * beside an event is a screen nobody can read.
 *
 * A rename and not a rewrite of `Version20260823170000`: that migration is
 * already applied, and editing applied SQL means a fresh installation builds a
 * different schema from an existing one. Renaming costs one migration and leaves
 * every database in the same shape.
 *
 * The table always exists here - `170000` creates it unconditionally and
 * migrations run in order - so this needs none of the branching
 * `Version20260823140000` had to grow.
 *
 * Written by hand, as every migration here has to be.
 */
final class Version20260823190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename core_planning_event_reminders to core_planning_event_alerts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_planning_event_reminders RENAME TO core_planning_event_alerts');
        $this->addSql('ALTER SEQUENCE seq_core_planning_event_reminder_id RENAME TO seq_core_planning_event_alert_id');
        $this->addSql('ALTER INDEX idx_planning_reminder_due RENAME TO idx_planning_alert_due');
        $this->addSql('ALTER INDEX uniq_planning_reminder_offset RENAME TO uniq_planning_alert_offset');
        // The constraint too. A foreign key named after the old word is the kind
        // of thing that reads as a leftover to whoever meets it next.
        $this->addSql('ALTER TABLE core_planning_event_alerts RENAME CONSTRAINT fk_planning_reminder_event TO fk_planning_alert_event');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_planning_event_alerts RENAME CONSTRAINT fk_planning_alert_event TO fk_planning_reminder_event');
        $this->addSql('ALTER INDEX uniq_planning_alert_offset RENAME TO uniq_planning_reminder_offset');
        $this->addSql('ALTER INDEX idx_planning_alert_due RENAME TO idx_planning_reminder_due');
        $this->addSql('ALTER SEQUENCE seq_core_planning_event_alert_id RENAME TO seq_core_planning_event_reminder_id');
        $this->addSql('ALTER TABLE core_planning_event_alerts RENAME TO core_planning_event_reminders');
    }
}
