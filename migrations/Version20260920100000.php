<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Une étape du tableau peut ne pas regarder le client.
 *
 * Vrai partout au départ, ce qui est l'état de toutes les colonnes existantes :
 * jusqu'ici le client voyait tout. Fermer d'office aurait vidé les espaces en
 * cours le jour de la mise à jour.
 */
final class Version20260920100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A board column can be kept from the client';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_content_columns ADD visible_to_client BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_content_columns DROP visible_to_client');
    }
}
