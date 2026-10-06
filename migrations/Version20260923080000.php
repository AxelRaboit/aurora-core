<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A colour on a folder, to recognise it without reading it.
 *
 * In clear, unlike the name: `#rrggbb` says nothing about what the folder
 * contains, and a readable column can be sorted and counted in SQL the day a
 * screen asks for it. Seven characters, the form the house colour picker
 * produces and the document label already carries.
 */
final class Version20260923080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: a colour on a folder';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_folders ADD color VARCHAR(7) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_folders DROP color');
    }
}
