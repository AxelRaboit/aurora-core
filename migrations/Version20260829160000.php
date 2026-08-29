<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets a share follow the `[[links]]` inside the note.
 *
 * The column shipped a few hours after the table because the first version
 * answered the wrong question: it carried the notes filed *under* a note, and
 * what people mean by "the notes inside my note" in a wiki-link editor is the
 * ones a link points at. Both exist now, as two separate switches, because they
 * are two different acts with two different risks - a tree is bounded by how
 * you filed things, a link graph is not.
 *
 * Defaults to false, so every link already created keeps exposing exactly what
 * it exposed before this ran.
 *
 * Written by hand, as every migration here has to be.
 */
final class Version20260829160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add include_linked to core_notes_markdown_share_links';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_share_links ADD include_linked BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_share_links DROP include_linked');
    }
}
