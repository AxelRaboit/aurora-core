<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Une note est partagée avec l'équipe, ou personnelle.
 *
 * Écrite à la main, comme celles qui précèdent : `migrations:diff` sur cette
 * base de développement propose aussi de renommer deux douzaines d'index qu'il
 * n'a pas créés.
 *
 * Les notes existantes deviennent partagées, et c'est la lecture juste : elles
 * ont été prises quand la seule façon d'en prendre était sur le mur commun.
 *
 * Aucun index : le filtre ne s'applique jamais seul, toujours sous l'espace,
 * et l'index composite qui sert déjà la lecture amène assez peu de lignes pour
 * que le reste se lise en mémoire.
 */
final class Version20260917160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shared or personal notes on a client space';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE core_studio_space_notes ADD visibility VARCHAR(20) DEFAULT 'shared' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_notes DROP visibility');
    }
}
