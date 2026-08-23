<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Brings the planning tables back under a mapped module.
 *
 * `core_plannings`, `core_planning_events` and `core_planning_event_attendees`
 * have existed on every installation since the May squash, which created the
 * whole 110-table schema while Planning was still part of core. The module was
 * extracted a week later and archived; the tables stayed, mapped by nothing.
 * They are empty everywhere, so this alters them rather than dropping and
 * recreating - the indexes on them, including the unique `(source_type,
 * source_id)` that keeps a module from pushing the same record twice, are worth
 * keeping exactly as they are.
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
        // Dropping the column takes its foreign key and index with it.
        $this->addSql('ALTER TABLE core_plannings DROP agency_id');

        $this->addSql('ALTER TABLE core_plannings DROP color');
        $this->addSql('ALTER TABLE core_plannings ADD colour_slot INT DEFAULT 1 NOT NULL');

        $this->addSql('ALTER SEQUENCE seq_core_core_planning_id RENAME TO seq_core_planning_id');
        $this->addSql('ALTER SEQUENCE seq_core_core_planning_event_id RENAME TO seq_core_planning_event_id');
    }

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
