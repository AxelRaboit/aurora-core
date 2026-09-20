<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un lien d'accès peut montrer le dossier Drive, ou non.
 *
 * Vrai partout au départ : c'est le comportement des liens déjà dehors, et un
 * droit ajouté qui vaudrait faux leur retirerait le dossier sans que personne
 * l'ait demandé.
 */
final class Version20260920110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An access link can be kept from the Drive folder';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_access_links ADD can_see_drive BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_access_links DROP can_see_drive');
    }
}
