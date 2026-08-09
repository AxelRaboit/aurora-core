<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Whether a publication prints its own title and summary on its page.
 *
 * Some pages open with a banner or a grid that already says what they are, and
 * the plain heading under it then reads as the same thing twice. This is the
 * switch for that, and it belongs on the post rather than on a translation: it
 * is a decision about the page's design, and a design is written once for every
 * language.
 *
 * Defaults to true, so every publication that exists keeps rendering exactly
 * what it renders today. The DEFAULT is also required rather than cosmetic —
 * PostgreSQL refuses to add a NOT NULL column to a table that already has rows
 * without one.
 *
 * Written by hand, as with every migration in this module: `make:migration`
 * compares the dev database against the entities currently mapped, and every
 * extracted module absent from this checkout shows up as a table to drop. The
 * generated file for this one column carried 68 `DROP TABLE` statements.
 */
final class Version20260809105300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let a publication hide its own title and summary on the rendered page';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts ADD title_visible BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts DROP title_visible');
    }
}
