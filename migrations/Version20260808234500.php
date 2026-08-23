<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the content grid, stored in two halves like the banner.
 *
 * `core_posts.grid_layout` holds the arrangement - which zones exist, in what
 * order, how wide, of what kind - shared by every language.
 * `core_post_translations.grid` holds what each zone contains in that locale,
 * keyed by zone id.
 *
 * Nothing to convert: the grid is new, and `blocks` keeps rendering as it
 * does. What becomes of `blocks` once the grid can do its job is a decision
 * for its own migration, taken when there is something to migrate to.
 *
 * Written by hand rather than generated, as with every migration in this
 * module: `migrations:diff` still wants to drop every table belonging to the
 * extracted modules, which buries the two statements that matter.
 */
final class Version20260808234500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the content grid: its layout on the post, its content per translation';
    }

    public function up(Schema $schema): void
    {
        // DEFAULT is required, not cosmetic: both tables already have rows,
        // and PostgreSQL refuses to add a NOT NULL column to them without one.
        $this->addSql("ALTER TABLE core_posts ADD grid_layout JSON DEFAULT '[]' NOT NULL");
        $this->addSql("ALTER TABLE core_post_translations ADD grid JSON DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts DROP grid_layout');
        $this->addSql('ALTER TABLE core_post_translations DROP grid');
    }
}
