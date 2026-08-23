<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds reminders: a second kind of thing on the calendar, which you tick off.
 *
 * Its own table rather than a `type` column on events, and the columns say why.
 * `end_at` means nothing on a reminder and `completed_at` means nothing on an
 * event, so one table would be two half-filled shapes plus a `WHERE type =` on
 * every query that the structure should have made unnecessary.
 *
 * Two indexes, one per question that gets asked constantly. `(due_at,
 * notified_at, completed_at)` is the worker's, once a minute for the life of the
 * application, in the order the planner narrows it. `(planning_id, due_at)` is
 * the grid's: one calendar's reminders in a window.
 *
 * `due_at` is NOT NULL, unlike Apple, which allows a reminder with no date. A
 * dateless reminder cannot be drawn on a calendar and this module is a calendar;
 * a list of undated intentions is a different product with a different screen.
 *
 * Written by hand, as every migration here has to be.
 */
final class Version20260823200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add core_planning_reminders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_planning_reminder_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql(<<<'SQL'
            CREATE TABLE core_planning_reminders (
              id INT NOT NULL,
              planning_id INT NOT NULL,
              title VARCHAR(255) NOT NULL,
              notes TEXT DEFAULT NULL,
              due_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              all_day BOOLEAN DEFAULT false NOT NULL,
              completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
              notified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              PRIMARY KEY (id)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_planning_reminder_due
              ON core_planning_reminders (due_at, notified_at, completed_at)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_planning_reminder_planning_due
              ON core_planning_reminders (planning_id, due_at)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE core_planning_reminders
              ADD CONSTRAINT fk_planning_reminder_planning
              FOREIGN KEY (planning_id) REFERENCES core_plannings (id)
              ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core_planning_reminders');
        $this->addSql('DROP SEQUENCE seq_core_planning_reminder_id');
    }
}
