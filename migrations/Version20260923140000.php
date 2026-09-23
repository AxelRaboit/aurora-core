<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le partage interne : une date sur le dossier et sur la note.
 *
 * Une date plutôt qu'un booléen, comme pour l'épinglage : « partagé le »
 * est une information qu'un booléen jette, et c'est la première qu'on
 * cherche devant une note qui n'est plus tout à fait à soi.
 *
 * Toutes les lignes existantes restent à null, donc **rien ne change** : un
 * carnet reste privé tant que personne n'a rien partagé.
 */
final class Version20260923140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: share a folder or a note with the rest of the back office';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_folders ADD shared_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD shared_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // Ce qui est partagé se cherche par cette colonne à chaque
        // affichage du panneau, et presque toutes les lignes sont nulles.
        $this->addSql('CREATE INDEX idx_notes_folders_shared ON core_notes_markdown_folders (shared_at)');
        $this->addSql('CREATE INDEX idx_notes_md_shared ON core_notes_markdown_notes (shared_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_notes_folders_shared');
        $this->addSql('DROP INDEX idx_notes_md_shared');
        $this->addSql('ALTER TABLE core_notes_markdown_folders DROP shared_at');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP shared_at');
    }
}
