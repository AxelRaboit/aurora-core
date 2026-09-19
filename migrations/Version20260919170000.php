<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un espace peut désigner le dossier Drive que son client a partagé.
 *
 * Nul partout au départ, ce qui est l'état de tous les espaces existants :
 * aucun n'a de Drive, et la plupart n'en auront jamais. L'identifiant et non
 * l'adresse - c'est ce que Google attend, et c'est ce qui suit `/folders/`.
 */
final class Version20260919170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A space can name the Drive folder its client shared';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_customer_spaces ADD drive_folder_id VARCHAR(128) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_customer_spaces DROP COLUMN drive_folder_id');
    }
}
