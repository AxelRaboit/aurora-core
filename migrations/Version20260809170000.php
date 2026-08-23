<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the plain block column, now that nothing reads it.
 *
 * **This one deletes words, and `down()` cannot bring them back.** Every other
 * migration in this module undoes itself; this one recreates an empty column
 * and nothing else. It is written last on purpose, and only once each of these
 * had stopped reading it:
 *
 * - `PostPageRenderer`, which no longer passes `content` to a template;
 * - the default theme, which no longer has a branch for it;
 * - `PostTextExtractor`, which reads the grid - it went on returning the
 *   pre-migration string while this column existed, so search kept answering
 *   with answers a version out of date;
 * - the demo fixtures, which write a one-zone grid;
 * - `PostManager`, whose revision snapshots take the grid and its arrangement
 *   rather than this;
 * - `aurora-client`, whose theme was the last consumer and has been updated.
 *
 * The words themselves were copied into `core_post_translations.grid` by
 * {@see Version20260809150000}, which is what makes this a removal rather than
 * a loss. Anyone restoring an older dump gets both columns and can re-run that
 * migration.
 *
 * Written by hand, as with every migration in this module: `make:migration`
 * compares the dev database against the entities currently mapped, and every
 * extracted module absent from this checkout reads as a table to drop.
 */
final class Version20260809170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the plain block column, superseded by the content grid';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_post_translations DROP blocks');
    }

    /**
     * Gives the column back, empty. The words are in `grid` and this cannot
     * put them here - a migration that pretended otherwise would be worse than
     * one that says so.
     */
    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE core_post_translations ADD blocks JSON DEFAULT '[]' NOT NULL");
    }
}
