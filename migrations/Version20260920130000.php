<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La génération des sessions ouvertes sur le Drive d'un espace.
 *
 * Nulle partout au départ, ce qui referme les sessions en cours : elles
 * retiennent une génération qui ne correspond plus à rien. C'est l'effet
 * voulu, et il ne coûte qu'une ressaisie.
 */
final class Version20260920130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A space carries the generation of its open Drive sessions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_customer_spaces ADD drive_lock_generation VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_customer_spaces DROP drive_lock_generation');
    }
}
