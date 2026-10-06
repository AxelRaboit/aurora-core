<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Note history: their past versions, encrypted like the notes themselves.
 *
 * A version is kept just before the save that replaces it, at most one every
 * ten minutes by default, fifty per note (settings > Notes). It goes away with
 * its note.
 */
final class Version20261003120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: past versions of a note';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_notes_markdown_revision_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE core_notes_markdown_revisions (id INT NOT NULL, note_id INT NOT NULL, author_id INT DEFAULT NULL, title TEXT DEFAULT NULL, content TEXT DEFAULT NULL, note_version INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_notes_md_revision_note ON core_notes_markdown_revisions (note_id, created_at)');
        $this->addSql('CREATE INDEX IDX_44868C83F675F31B ON core_notes_markdown_revisions (author_id)');
        $this->addSql('ALTER TABLE core_notes_markdown_revisions ADD CONSTRAINT FK_NOTES_MD_REVISION_NOTE FOREIGN KEY (note_id) REFERENCES core_notes_markdown_notes (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_notes_markdown_revisions ADD CONSTRAINT FK_NOTES_MD_REVISION_AUTHOR FOREIGN KEY (author_id) REFERENCES core_users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core_notes_markdown_revisions');
        $this->addSql('DROP SEQUENCE seq_core_notes_markdown_revision_id');
    }
}
