<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Comments on a note, on a passage of it (09/10/2026): threads, replies and
 * their settling, gone with the note.
 */
final class Version20261009180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: comments on a note';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_notes_comment_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE core_notes_comments (guest_name VARCHAR(80) DEFAULT NULL, quote TEXT DEFAULT NULL, body TEXT NOT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id INT NOT NULL, author_id INT DEFAULT NULL, note_id INT NOT NULL, parent_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C8C43C04F675F31B ON core_notes_comments (author_id)');
        $this->addSql('CREATE INDEX IDX_C8C43C0426ED0855 ON core_notes_comments (note_id)');
        $this->addSql('CREATE INDEX IDX_C8C43C04727ACA70 ON core_notes_comments (parent_id)');
        $this->addSql('ALTER TABLE core_notes_comments ADD CONSTRAINT FK_C8C43C04F675F31B FOREIGN KEY (author_id) REFERENCES core_users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_notes_comments ADD CONSTRAINT FK_C8C43C0426ED0855 FOREIGN KEY (note_id) REFERENCES core_notes_markdown_notes (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_notes_comments ADD CONSTRAINT FK_C8C43C04727ACA70 FOREIGN KEY (parent_id) REFERENCES core_notes_comments (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core_notes_comments');
        $this->addSql('DROP SEQUENCE seq_core_notes_comment_id');
    }
}
