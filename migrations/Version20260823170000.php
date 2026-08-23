<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the calendar's reminders.
 *
 * A table rather than a JSON column on the event, and that is the whole design
 * decision. The worker asks "what is due now" every minute for the life of the
 * application; against a column that is an index lookup, and against JSON it is a
 * scan of every event that ever existed.
 *
 * `remind_at` is stored rather than derived from `start_at` for the same reason,
 * and the index is on `(remind_at, sent_at)` because the query filters on both:
 * due, and not yet sent.
 *
 * `(event_id, minutes_before)` is unique. Two reminders at the same offset on one
 * event is a double notification, and it is the kind of duplicate a form produces
 * by being submitted twice rather than by anyone meaning it.
 *
 * Written by hand, as every migration here has to be.
 */
final class Version20260823170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add core_planning_event_reminders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_planning_event_reminder_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql(<<<'SQL'
            CREATE TABLE core_planning_event_reminders (
              id INT NOT NULL,
              event_id INT NOT NULL,
              minutes_before INT DEFAULT 30 NOT NULL,
              remind_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
              PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_planning_reminder_due ON core_planning_event_reminders (remind_at, sent_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_planning_reminder_offset ON core_planning_event_reminders (event_id, minutes_before)');
        $this->addSql(<<<'SQL'
            ALTER TABLE core_planning_event_reminders
              ADD CONSTRAINT fk_planning_reminder_event
              FOREIGN KEY (event_id) REFERENCES core_planning_events (id)
              ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core_planning_event_reminders');
        $this->addSql('DROP SEQUENCE seq_core_planning_event_reminder_id');
    }
}
