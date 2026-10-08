<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A note can be handed to somebody who is not in its space.
 *
 * Until now the only unit of sharing was the space: showing one page to one
 * person meant opening a whole notebook to them, and moving the page back out
 * afterwards is not something anybody remembers to do. This table answers the
 * other question - "just this note, just this person" - and answers nothing
 * else: a row here reaches one note's text and no other row of the space that
 * holds it.
 *
 * **It only ever adds.** The rule is read next to the space rule with an `OR`,
 * never instead of it, so nothing anybody could already see becomes hidden and
 * no existing row changes meaning. Which is why this migration creates a table
 * and touches nothing.
 *
 * Both keys cascade: without the note the row means nothing, and without the
 * account it would name somebody who no longer exists.
 */
final class Version20261008110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: a note shared with named people, reader or editor, outside its space';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_notes_markdown_note_member_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql("CREATE TABLE core_notes_markdown_note_members (id INT NOT NULL, note_id INT NOT NULL, user_id INT NOT NULL, role VARCHAR(16) DEFAULT 'reader' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))");
        // One row per pair, so adding somebody twice fails on insert rather
        // than leaving two roles for one person and no way to say which wins.
        $this->addSql('CREATE UNIQUE INDEX uniq_notes_markdown_member ON core_notes_markdown_note_members (note_id, user_id)');
        $this->addSql('CREATE INDEX idx_notes_markdown_member_note ON core_notes_markdown_note_members (note_id)');
        // Read on every list the module draws - "the notes handed to me" is a
        // subquery on this column.
        $this->addSql('CREATE INDEX idx_notes_markdown_member_user ON core_notes_markdown_note_members (user_id)');
        $this->addSql('ALTER TABLE core_notes_markdown_note_members ADD CONSTRAINT FK_48B25E7226ED0855 FOREIGN KEY (note_id) REFERENCES core_notes_markdown_notes (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_notes_markdown_note_members ADD CONSTRAINT FK_48B25E72A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core_notes_markdown_note_members');
        $this->addSql('DROP SEQUENCE seq_core_notes_markdown_note_member_id');
    }
}
