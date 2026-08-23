<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes the recurrence columns say what the mapping says.
 *
 * Two of my own mismatches, both found by running `schema:update` on the consumer
 * after propagating - which is the only place they show, because the development
 * database still carries the orphan sequences of the module split and they hide in
 * that noise.
 *
 * **`exdates` was created as TEXT and mapped as `json`.** Doctrine proposed the
 * alter on every check. JSON is the right type: the column holds a list, Postgres
 * can validate it, and reading it back as a string would put the parsing in PHP
 * for no reason.
 *
 * **`idx_planning_event_series` was created partial** - `WHERE rrule IS NOT NULL` -
 * and the ORM has no way to declare that, so it proposed to drop it for ever. The
 * partial clause bought very little: the index exists for a query that already
 * filters on `rrule IS NOT NULL`, and a plain index on the same two columns serves
 * it. Recreated without the clause and declared on the entity, so the two agree.
 *
 * The lesson is the one `uniq_planning_source` taught earlier in the same module:
 * an index a migration creates and the mapping does not know about is not an
 * optimisation, it is a permanent diff.
 *
 * Written by hand, as every migration here has to be.
 */
final class Version20260823270000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align exdates and the series index with the mapping';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_planning_events ALTER exdates TYPE JSON USING exdates::json');

        $this->addSql('DROP INDEX IF EXISTS idx_planning_event_series');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_planning_event_series
              ON core_planning_events (planning_id, recurrence_until)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_planning_event_series');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_planning_event_series
              ON core_planning_events (planning_id, recurrence_until)
              WHERE rrule IS NOT NULL
            SQL);
        $this->addSql('ALTER TABLE core_planning_events ALTER exdates TYPE TEXT');
    }
}
