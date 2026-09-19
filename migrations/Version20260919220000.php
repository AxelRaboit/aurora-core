<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Une carte peut porter une date sans paraître dans le calendrier.
 *
 * Vrai partout au départ, ce qui est l'état de toutes les cartes existantes :
 * jusqu'ici, une date valait une parution. Le défaut reste vrai pour que
 * personne n'ait à cocher quoi que ce soit pour retrouver ce qu'il avait.
 */
final class Version20260919220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A content item can carry a date without showing on the calendar';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_content_items ADD show_on_calendar BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_content_items DROP show_on_calendar');
    }
}
