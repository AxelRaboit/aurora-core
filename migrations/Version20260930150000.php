<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'épinglage et le partage en lecture quittent les notes et les dossiers.
 *
 * Les deux vivent ailleurs depuis la migration précédente : les favoris dans
 * leur table, une ligne par personne, et le partage dans les espaces. Les
 * colonnes n'étaient plus lues par rien.
 *
 * Le retour arrière rend à chaque ligne l'épinglage de son auteur, le seul
 * qu'une colonne sur la note savait porter ; le partage en lecture, lui,
 * revient vide.
 */
final class Version20260930150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: drop favorited_at and shared_at, now held by favorites and spaces';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_notes_md_favorited');
        $this->addSql('DROP INDEX IF EXISTS idx_notes_md_shared');
        $this->addSql('DROP INDEX IF EXISTS idx_notes_folders_favorited');
        $this->addSql('DROP INDEX IF EXISTS idx_notes_folders_shared');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP favorited_at');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP shared_at');
        $this->addSql('ALTER TABLE core_notes_markdown_folders DROP favorited_at');
        $this->addSql('ALTER TABLE core_notes_markdown_folders DROP shared_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD favorited_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD shared_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_folders ADD favorited_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_folders ADD shared_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE core_notes_markdown_notes n SET favorited_at = f.created_at FROM core_notes_favorites f WHERE f.note_id = n.id AND f.user_id = n.user_id');
        $this->addSql('UPDATE core_notes_markdown_folders d SET favorited_at = f.created_at FROM core_notes_favorites f WHERE f.folder_id = d.id AND f.user_id = d.user_id');
        $this->addSql('CREATE INDEX idx_notes_md_favorited ON core_notes_markdown_notes (favorited_at)');
        $this->addSql('CREATE INDEX idx_notes_md_shared ON core_notes_markdown_notes (shared_at)');
        $this->addSql('CREATE INDEX idx_notes_folders_favorited ON core_notes_markdown_folders (favorited_at)');
        $this->addSql('CREATE INDEX idx_notes_folders_shared ON core_notes_markdown_folders (shared_at)');
    }
}
