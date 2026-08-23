<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives attendees a table that something maps, and an answer to record.
 *
 * `core_planning_event_attendees` has existed since the May squash on the
 * installations that ran it, mapped by nothing, and `schema:update` has been
 * proposing to drop it ever since. It is dropped and recreated rather than
 * altered, and that is safe for one reason worth stating: nothing has ever mapped
 * it, so no ORM has ever written to it - it is empty wherever it exists. Checked
 * before writing this.
 *
 * Recreated because the shape has to change twice over. It needs a status, since a
 * list of attendees without one says who was asked rather than who is coming; and
 * it needs a surrogate id, because every route that names one attendee would
 * otherwise have to carry two values.
 *
 * `DROP TABLE IF EXISTS` rather than a branch on `hasTable`: the two starting
 * points differ only in whether the table is there, and one statement covers both.
 * `Version20260823140000` needed a branch because it had to *keep* what it found.
 *
 * Written by hand, as every migration here has to be.
 */
final class Version20260823260000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recreate core_planning_event_attendees with a response status';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS core_planning_event_attendees');

        $this->addSql('CREATE SEQUENCE IF NOT EXISTS seq_core_planning_event_attendee_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql(<<<'SQL'
            CREATE TABLE core_planning_event_attendees (
              id INT NOT NULL,
              event_id INT NOT NULL,
              user_id INT NOT NULL,
              status VARCHAR(20) DEFAULT 'needs_action' NOT NULL,
              responded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
              PRIMARY KEY (id)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_planning_attendee
              ON core_planning_event_attendees (event_id, user_id)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_planning_attendee_user
              ON core_planning_event_attendees (user_id, status)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE core_planning_event_attendees
              ADD CONSTRAINT fk_planning_attendee_event
              FOREIGN KEY (event_id) REFERENCES core_planning_events (id)
              ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE core_planning_event_attendees
              ADD CONSTRAINT fk_planning_attendee_user
              FOREIGN KEY (user_id) REFERENCES core_users (id)
              ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core_planning_event_attendees');
        $this->addSql('DROP SEQUENCE seq_core_planning_event_attendee_id');
    }
}
