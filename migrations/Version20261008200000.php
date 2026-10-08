<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A past version of a note can name everybody who was writing it.
 *
 * **One column because one name was wrong.** During a co-editing session a
 * single elected browser sends the write-back for the whole room, so every
 * version kept during that session carried the saver's name alone - somebody
 * who may not have typed a word of it. `author` still says who saved; this
 * says who wrote.
 *
 * **Null for every existing row, and for almost every future one.** It is
 * filled only when the server saw more than one person in the editor at that
 * moment, which keeps the column empty for ordinary single-handed saves
 * instead of repeating the author in a second place.
 *
 * The names are copied rather than related to accounts on purpose: a version
 * is the record of a moment, so the name worth showing is the name as it was,
 * and closing an account must not turn "written by two people" back into
 * "written by one". Reversible without loss of anything the note holds - the
 * text lives in `content`, this only says whose hands it passed through.
 */
final class Version20261008200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Names every writer on a note revision, not only the one who saved it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_revisions ADD written_by JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_revisions DROP written_by');
    }
}
