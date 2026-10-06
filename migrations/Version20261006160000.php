<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Two columns the Notes module needs before it can take over the notes of a
 * customer space.
 *
 * - `core_notes_spaces.managed_by`: what sets the space's name, access and
 *   members in its managers' stead (`studio.customer_space` for the note space
 *   of a customer space). Null for every space that exists today.
 * - `core_notes_markdown_notes.craft_document_id`: the Craft document a note is
 *   a copy of, so it can be put back on the document's current version.
 */
final class Version20261006160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: managed_by on note spaces, craft_document_id on notes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_spaces ADD managed_by VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD craft_document_id VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP craft_document_id');
        $this->addSql('ALTER TABLE core_notes_spaces DROP managed_by');
    }
}
