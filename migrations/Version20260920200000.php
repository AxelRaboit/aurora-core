<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A customer's information sheet, and the resources of a space.
 *
 * **Four columns on the customer**, because the sheet belongs to the customer
 * and not to the project: a SIRET belongs to a company, and two spaces opened
 * for the same one cannot contradict each other. Three of them complete what
 * contracts already filled in - the SIREN next to the SIRET, the landline
 * next to the mobile - and the fourth keeps whatever fits in no field.
 *
 * **A table for the resources**, which belong to the space: a Canva link, a
 * dashboard, the person who approves. Each carries its own visibility, closed
 * by default, because a list holding both what is shared and what is not
 * only makes sense if a checkbox decides, row by row.
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
        // `DEFAULT '[]'` and not nullable: an empty list is a list, and
        // telling "no link" apart from "no links" teaches nobody anything
        // while forcing every read to test for null.
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
