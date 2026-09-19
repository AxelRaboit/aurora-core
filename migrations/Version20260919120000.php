<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Une note sait de quel document Craft elle est la copie.
 *
 * Nulle partout au départ, ce qui est l'état de toutes les notes existantes :
 * aucune n'a été importée. La colonne sert à retrouver la source et, plus
 * tard, à reconnaître un réimport du même document plutôt que d'en créer une
 * seconde à côté de la première.
 *
 * Soixante-quatre caractères : un identifiant de bloc Craft est un UUID avec
 * des tirets, et la marge évite d'y revenir si leur forme change.
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
