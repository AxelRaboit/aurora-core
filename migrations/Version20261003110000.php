<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Une note peut servir de modèle.
 *
 * « Nouvelle note depuis un modèle » en fait une copie : un brief client, un
 * compte rendu, une procédure, prêts à remplir. Le modèle reste une note
 * ordinaire, qu'on lit et qu'on modifie ; seul ce drapeau le distingue.
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
