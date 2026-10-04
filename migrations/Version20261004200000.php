<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les sections de grille qu'une personne garde pour les réinsérer : une suite
 * de zones et leurs mots, à elle seule.
 */
final class Version20261004200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Saved grid sections, one person\'s own';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_grid_section_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE core_editorial_grid_sections (name VARCHAR(120) NOT NULL, layout JSON NOT NULL, content JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id INT NOT NULL, owner_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_grid_section_owner ON core_editorial_grid_sections (owner_id)');
        $this->addSql('ALTER TABLE core_editorial_grid_sections ADD CONSTRAINT FK_AA17E3DE7E3C61F9 FOREIGN KEY (owner_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_editorial_grid_sections DROP CONSTRAINT FK_AA17E3DE7E3C61F9');
        $this->addSql('DROP TABLE core_editorial_grid_sections');
        $this->addSql('DROP SEQUENCE seq_core_grid_section_id');
    }
}
