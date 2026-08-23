<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Brings the planning tables back under a mapped module.
 *
 * Two starting points, and that is why this migration branches. The May squash
 * created the whole 110-table schema while Planning was still part of core, so an
 * installation that ran it has `core_plannings` and `core_planning_events`
 * already - mapped by nothing since the module was extracted a week later and
 * archived. An installation that came up afterwards registered the squash as a
 * baseline with no `executed_at` and never created them at all.
 *
 * The second case was found the hard way: this migration assumed the first, and
 * failed on aurora-client with `relation "core_plannings" does not exist`. A
 * migration in a library cannot assume which of its own past states a consumer
 * is standing on.
 *
 * Where the tables exist they are altered rather than dropped and recreated:
 * they are empty everywhere, but the indexes on them - including the unique
 * `(source_type, source_id)` that keeps a module from pushing the same record
 * twice - are worth keeping exactly as they are. Where they do not, they are
 * created in the shape the alterations would have produced.
 *
 * `core_planning_event_attendees` is deliberately not created by the fresh path.
 * No entity maps it, so an installation without it is an installation with less
 * noise in `schema:update`; the ones that already have it keep it until
 * attendees are actually built.
 *
 * Three changes, and one of them is a decision rather than a repair:
 *
 * **`color` becomes `colour_slot`.** The column held a hex string with a default
 * of `#3b82f6`. A stored hex cannot follow the theme, so a colour picked against
 * a white page is whatever it happens to be against a dark one; and a free
 * picker cannot promise that two calendars stay distinguishable - `#ff00ff` is a
 * legal answer and an unreadable one. A slot points into the shared categorical
 * palette (`css/base/chart.css`), which has a step per mode and was checked for
 * separation under colour-vision deficiency.
 *
 * **`agency_id` goes**, with its foreign key: the Agency module is not in core
 * any more.
 *
 * **The sequences are renamed.** They were `seq_core_core_planning_id` and
 * `seq_core_core_planning_event_id` - the prefix applied twice, against the
 * `seq_core_<entity>_id` convention. Empty tables make this the cheap moment.
 *
 * Written by hand, as every migration here has to be: `migrations:diff` compares
 * the dev database against the entities currently mapped, and every module absent
 * from this checkout reads as a table to drop.
 */
final class Version20260823140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Re-map the planning tables: colour slot, no agency, conventional sequence names';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('core_plannings')) {
            $this->adaptExistingTables();

            return;
        }

        $this->createTables();
    }

    /**
     * The path for an installation that ran the May squash.
     *
     * Three changes, and one of them is a decision rather than a repair. See the
     * class docblock for `color` becoming `colour_slot`.
     */
    private function adaptExistingTables(): void
    {
        // Dropping the column takes its foreign key and index with it.
        $this->addSql('ALTER TABLE core_plannings DROP agency_id');

        $this->addSql('ALTER TABLE core_plannings DROP color');
        $this->addSql('ALTER TABLE core_plannings ADD colour_slot INT DEFAULT 1 NOT NULL');

        $this->addSql('ALTER SEQUENCE seq_core_core_planning_id RENAME TO seq_core_planning_id');
        $this->addSql('ALTER SEQUENCE seq_core_core_planning_event_id RENAME TO seq_core_planning_event_id');
    }

    /**
     * The path for an installation that never had these tables.
     *
     * The squash's shape with the alterations already applied: `colour_slot`
     * instead of `color`, no `agency_id`, and the sequences named the way the
     * convention wants rather than renamed a statement later.
     */
    private function createTables(): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_planning_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_planning_event_id INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql(<<<'SQL'
            CREATE TABLE core_plannings (
              id INT NOT NULL,
              owner_id INT DEFAULT NULL,
              name VARCHAR(150) NOT NULL,
              description TEXT DEFAULT NULL,
              colour_slot INT DEFAULT 1 NOT NULL,
              timezone VARCHAR(64) DEFAULT 'Europe/Paris' NOT NULL,
              visibility VARCHAR(20) DEFAULT 'private' NOT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_6431B97E3C61F9 ON core_plannings (owner_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE core_plannings
              ADD CONSTRAINT FK_6431B97E3C61F9
              FOREIGN KEY (owner_id) REFERENCES core_users (id)
              ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE core_planning_events (
              id INT NOT NULL,
              planning_id INT NOT NULL,
              title VARCHAR(255) NOT NULL,
              description TEXT DEFAULT NULL,
              location VARCHAR(255) DEFAULT NULL,
              start_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              end_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              all_day BOOLEAN DEFAULT false NOT NULL,
              status VARCHAR(20) DEFAULT 'confirmed' NOT NULL,
              source_type VARCHAR(64) DEFAULT NULL,
              source_id INT DEFAULT NULL,
              source_label VARCHAR(255) DEFAULT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_4E5AB77D3D865311 ON core_planning_events (planning_id)');
        $this->addSql('CREATE INDEX idx_planning_event_planning_start ON core_planning_events (planning_id, start_at)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_planning_event_source ON core_planning_events (source_type, source_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE core_planning_events
              ADD CONSTRAINT FK_4E5AB77D3D865311
              FOREIGN KEY (planning_id) REFERENCES core_plannings (id)
              ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);
    }

    /**
     * Rolls back to the squash's shape, which is the only shape worth going back
     * to: an installation that never had these tables has nothing here it wants.
     * Dropping them instead would make `down` destroy data on one kind of install
     * and restore a column on the other.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER SEQUENCE seq_core_planning_event_id RENAME TO seq_core_core_planning_event_id');
        $this->addSql('ALTER SEQUENCE seq_core_planning_id RENAME TO seq_core_core_planning_id');

        $this->addSql('ALTER TABLE core_plannings DROP colour_slot');
        $this->addSql("ALTER TABLE core_plannings ADD color VARCHAR(7) DEFAULT '#3b82f6' NOT NULL");

        // The agency column comes back without its foreign key: `core_agencies`
        // is itself an orphan of the same split, and a constraint against a table
        // no entity maps is a rollback that fails on some installs and not
        // others.
        $this->addSql('ALTER TABLE core_plannings ADD agency_id INT DEFAULT NULL');
    }
}
