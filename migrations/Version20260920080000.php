<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'onglet Drive d'un espace peut être fermé par un mot de passe.
 *
 * Haché et non chiffré : un mot de passe n'a jamais besoin d'être relu,
 * seulement comparé. Nul partout au départ, c'est-à-dire ouvert, ce qui est
 * l'état de tous les espaces existants.
 */
final class Version20260920080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A space can close its Drive tab behind a password';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_customer_spaces ADD drive_password VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_customer_spaces DROP drive_password');
    }
}
