<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les favoris : une date d'épinglage sur une note et sur un dossier.
 *
 * Une date plutôt qu'un booléen, sur les deux tables : elle donne l'ordre du
 * panneau sans colonne de plus, et « épinglé le » est une information qu'un
 * booléen jette.
 *
 * L'index est déclaré sur l'entité comme les autres : un index partiel
 * (`WHERE favorited_at IS NOT NULL`) irait mieux à une colonne presque
 * toujours vide, mais il ne s'écrit pas dans les attributs Doctrine, et
 * `doctrine:schema:validate` proposerait de le supprimer à chaque
 * exécution.
 */
final class Version20260922233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: pin a note or a folder to the side menu';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD favorited_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_folders ADD favorited_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_notes_md_favorited ON core_notes_markdown_notes (favorited_at)');
        $this->addSql('CREATE INDEX idx_notes_folders_favorited ON core_notes_markdown_folders (favorited_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_notes_md_favorited');
        $this->addSql('DROP INDEX idx_notes_folders_favorited');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP favorited_at');
        $this->addSql('ALTER TABLE core_notes_markdown_folders DROP favorited_at');
    }
}
