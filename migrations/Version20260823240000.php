<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recurring events: one row plus a rule, not one row per occurrence.
 *
 * Storing every occurrence would mean "every Monday, for ever" is a table that
 * grows without bound and a form that has to ask how far into the future to
 * write. A rule expanded over the window being drawn answers both.
 *
 * Five columns, and each earns its place:
 *
 * **`rrule`** is the rule as RFC 5545 writes it, so it round-trips through the
 * iCalendar feed unchanged and can be widened later without a data migration.
 *
 * **`recurrence_until`** is the last occurrence's end, denormalised, and it is
 * what keeps the window query cheap: without it, finding which recurring events
 * touch a month means expanding every rule in the table on every fetch. NULL
 * means the series never ends.
 *
 * **`exdates`** holds the occurrences somebody deleted, as a JSON array of their
 * original starts. A column rather than a table because nothing queries them
 * except the expander, which has the row in hand already.
 *
 * **`master_id`** and **`occurrence_at`** are the other half of an exception: an
 * occurrence somebody edited becomes a real row of its own, pointing at the series
 * it left and at the date it replaces. The expander skips that date, so the two
 * never both appear.
 *
 * Deleting an occurrence writes an exdate; editing one writes a child. Both are
 * exclusions, and keeping them separate is what lets "deleted" and "moved" be
 * told apart afterwards.
 *
 * Written by hand, as every migration here has to be.
 */
final class Version20260823240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recurrence to core_planning_events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_planning_events ADD rrule VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_planning_events ADD recurrence_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE core_planning_events ADD exdates TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE core_planning_events ADD master_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE core_planning_events ADD occurrence_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        $this->addSql(<<<'SQL'
            ALTER TABLE core_planning_events
              ADD CONSTRAINT fk_planning_event_master
              FOREIGN KEY (master_id) REFERENCES core_planning_events (id)
              ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);

        // The expander's lookup: the children of a series, by the date each one
        // replaces. Unique, because two rows claiming the same occurrence is a
        // state with no correct rendering.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_planning_event_occurrence
              ON core_planning_events (master_id, occurrence_at)
            SQL);

        // The window query: which series could reach the range being drawn.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_planning_event_series
              ON core_planning_events (planning_id, recurrence_until)
              WHERE rrule IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_planning_event_series');
        $this->addSql('DROP INDEX uniq_planning_event_occurrence');
        $this->addSql('ALTER TABLE core_planning_events DROP CONSTRAINT fk_planning_event_master');
        $this->addSql('ALTER TABLE core_planning_events DROP occurrence_at');
        $this->addSql('ALTER TABLE core_planning_events DROP master_id');
        $this->addSql('ALTER TABLE core_planning_events DROP exdates');
        $this->addSql('ALTER TABLE core_planning_events DROP recurrence_until');
        $this->addSql('ALTER TABLE core_planning_events DROP rrule');
    }
}
