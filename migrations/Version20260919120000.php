<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A note knows which Craft document it is a copy of.
 *
 * Null everywhere at first, which is the state of every existing note: none
 * was imported. The column is used to find the source again and, later, to
 * recognise a re-import of the same document rather than create a second note
 * next to the first.
 *
 * Sixty-four characters: a Craft block id is a UUID with hyphens, and the
 * margin avoids coming back to it if their shape changes.
 */
final class Version20260919120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A note knows which Craft document it is a copy of';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_notes ADD craft_document_id VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_notes DROP COLUMN craft_document_id');
    }
}
