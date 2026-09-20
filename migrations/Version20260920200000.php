<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La fiche d'un client, et les ressources d'un espace.
 *
 * **Quatre colonnes sur le client**, parce que la fiche est au client et non
 * au projet : un SIRET appartient à une société, et deux espaces ouverts pour
 * la même ne peuvent pas se contredire. Trois d'entre elles complètent ce que
 * les contrats remplissaient déjà - le SIREN à côté du SIRET, le fixe à côté
 * du portable - et la quatrième garde ce qui n'entre dans aucune case.
 *
 * **Une table pour les ressources**, qui sont, elles, à l'espace : un lien
 * Canva, un tableau de bord, la personne qui valide. Chacune porte sa propre
 * visibilité, fermée par défaut, parce qu'une liste où l'on range à la fois ce
 * qui se partage et ce qui ne se partage pas n'a de sens que si c'est une case
 * qui décide, ligne par ligne.
 */
final class Version20260920200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A customer information sheet, and the pinned resources of a space';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_customers ADD siren VARCHAR(9) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_customers ADD landline VARCHAR(30) DEFAULT NULL');
        // `DEFAULT '[]'` et non nullable : une liste vide est une liste, et
        // distinguer « aucun lien » de « pas de liens » n'apprend rien à
        // personne tout en obligeant chaque lecture à tester le null.
        $this->addSql("ALTER TABLE core_customers ADD links JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE core_customers ADD information_notes TEXT DEFAULT NULL');

        $this->addSql('CREATE SEQUENCE seq_core_space_resource_id INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql('CREATE TABLE core_studio_space_resources (id INT NOT NULL, space_id INT NOT NULL, kind VARCHAR(20) NOT NULL, label VARCHAR(180) NOT NULL, url VARCHAR(2048) DEFAULT NULL, body TEXT DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, phone VARCHAR(30) DEFAULT NULL, visible_to_client BOOLEAN DEFAULT false NOT NULL, position INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');

        $this->addSql('CREATE INDEX idx_space_resource_space_position ON core_studio_space_resources (space_id, position)');
        $this->addSql('CREATE INDEX IDX_836CE52423575340 ON core_studio_space_resources (space_id)');

        $this->addSql('ALTER TABLE core_studio_space_resources ADD CONSTRAINT FK_B1C0F2E923575340 FOREIGN KEY (space_id) REFERENCES core_studio_customer_spaces (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_resources DROP CONSTRAINT FK_B1C0F2E923575340');
        $this->addSql('DROP TABLE core_studio_space_resources');
        $this->addSql('DROP SEQUENCE seq_core_space_resource_id CASCADE');

        $this->addSql('ALTER TABLE core_customers DROP information_notes');
        $this->addSql('ALTER TABLE core_customers DROP links');
        $this->addSql('ALTER TABLE core_customers DROP landline');
        $this->addSql('ALTER TABLE core_customers DROP siren');
    }
}
