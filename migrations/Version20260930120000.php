<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A version number on every note.
 *
 * The editor saves on its own: with two people on the same note, the last one
 * typing overwrote the other without anyone knowing. The number goes up on
 * every write of the content, and a save made from an outdated version is
 * refused instead of overwriting. This is the prerequisite for a team space
 * where several people write.
 *
 * Every note starts at 1: nothing changes for someone writing alone.
 */
final class Version20260930120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: a version number that refuses a save made from an outdated copy';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP version');
    }
}
