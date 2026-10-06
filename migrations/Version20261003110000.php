<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A note can serve as a template.
 *
 * "Nouvelle note depuis un modèle" makes a copy of it: a client brief, meeting
 * minutes, a procedure, ready to fill in. The template stays an ordinary note,
 * read and edited as usual; only this flag sets it apart.
 */
final class Version20261003110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: a note can be marked as a template';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD is_template BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP is_template');
    }
}
