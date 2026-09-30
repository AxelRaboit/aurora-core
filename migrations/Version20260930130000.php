<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Deux espaces de notes : le sien, et celui de l'équipe.
 *
 * Chaque note et chaque dossier dit où il vit. Tout ce qui existe reste
 * **personnel** : rien ne quitte le carnet de personne à l'arrivée de
 * l'espace commun. On y range ensuite ce qu'on veut, à la main.
 */
final class Version20260930130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: a personal space and a team space for notes and folders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE core_notes_markdown_notes ADD space VARCHAR(16) DEFAULT 'personal' NOT NULL");
        $this->addSql("ALTER TABLE core_notes_markdown_folders ADD space VARCHAR(16) DEFAULT 'personal' NOT NULL");
        // L'espace d'équipe se lit sans propriétaire : ces index servent les
        // listes qui le demandent à chaque affichage.
        $this->addSql('CREATE INDEX idx_notes_md_space ON core_notes_markdown_notes (space)');
        $this->addSql('CREATE INDEX idx_notes_folders_space ON core_notes_markdown_folders (space)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_notes_md_space');
        $this->addSql('DROP INDEX idx_notes_folders_space');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP space');
        $this->addSql('ALTER TABLE core_notes_markdown_folders DROP space');
    }
}
