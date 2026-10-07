<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Internal sharing: a date on the folder and on the note.
 *
 * A date rather than a boolean, as for pinning: "shared on" is information a
 * boolean throws away, and it is the first one people look for in front of a
 * note that is no longer quite their own.
 *
 * All existing rows stay null, so **nothing changes**: a notebook stays
 * private as long as nobody has shared anything.
 */
final class Version20260923140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: share a folder or a note with the rest of the back office';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_folders ADD shared_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD shared_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // What is shared is looked up by this column every time the panel
        // shows, and nearly every row is null.
        $this->addSql('CREATE INDEX idx_notes_folders_shared ON core_notes_markdown_folders (shared_at)');
        $this->addSql('CREATE INDEX idx_notes_md_shared ON core_notes_markdown_notes (shared_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_notes_folders_shared');
        $this->addSql('DROP INDEX idx_notes_md_shared');
        $this->addSql('ALTER TABLE core_notes_markdown_folders DROP shared_at');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP shared_at');
    }
}
