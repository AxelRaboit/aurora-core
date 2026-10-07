<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Favourites: a pin date on a note and on a folder.
 *
 * A date rather than a boolean, on both tables: it gives the order of the
 * panel without an extra column, and "pinned on" is information that a
 * boolean throws away.
 *
 * The index is declared on the entity like the others: a partial index
 * (`WHERE favorited_at IS NOT NULL`) would suit a column that is almost
 * always empty better, but it cannot be written in Doctrine attributes, and
 * `doctrine:schema:validate` would offer to drop it on every run.
 */
final class Version20260922233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: pin a note or a folder to the side menu';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD favorited_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_folders ADD favorited_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_notes_md_favorited ON core_notes_markdown_notes (favorited_at)');
        $this->addSql('CREATE INDEX idx_notes_folders_favorited ON core_notes_markdown_folders (favorited_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_notes_md_favorited');
        $this->addSql('DROP INDEX idx_notes_folders_favorited');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP favorited_at');
        $this->addSql('ALTER TABLE core_notes_markdown_folders DROP favorited_at');
    }
}
