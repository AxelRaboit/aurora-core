<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Une couleur sur un dossier, pour le reconnaître sans le lire.
 *
 * En clair, contrairement au nom : `#rrggbb` ne dit rien de ce que le
 * dossier contient, et une colonne lisible se trie et se compte en SQL le
 * jour où un écran le demandera. Sept caractères, la forme que produit le
 * sélecteur de couleur de la maison et que porte déjà l'étiquette de
 * document.
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
