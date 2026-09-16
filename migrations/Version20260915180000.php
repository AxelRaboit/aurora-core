<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A client space, and who from the studio is on it.
 *
 * Written by hand rather than taken from `doctrine:migrations:diff`. The diff
 * on a development database that predates the removal of Billing, Crm,
 * Ecommerce and the rest proposes dropping every table those modules left
 * behind, which is a hundred kilobytes of destruction around the two tables
 * this actually adds. Only the statements naming the new tables are kept.
 *
 * `RESTRICT` on the customer and `CASCADE` on both sides of the membership row:
 * a company cannot be deleted while a space names it, and a space or an account
 * that goes takes its membership rows with it. The three choices are argued on
 * the entities themselves.
 */
final class Version20260915180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Client spaces, one per engagement, with their team';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_customer_space_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_customer_space_member_id INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql('CREATE TABLE core_studio_customer_spaces (id INT NOT NULL, customer_id INT NOT NULL, name VARCHAR(150) NOT NULL, description TEXT DEFAULT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, colour_slot INT DEFAULT 1 NOT NULL, timezone VARCHAR(64) DEFAULT \'Europe/Paris\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2945F8E69395C3F3 ON core_studio_customer_spaces (customer_id)');

        $this->addSql('CREATE TABLE core_studio_customer_space_members (id INT NOT NULL, space_id INT NOT NULL, user_id INT NOT NULL, role VARCHAR(20) DEFAULT \'member\' NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3FE19A3223575340 ON core_studio_customer_space_members (space_id)');
        $this->addSql('CREATE INDEX IDX_3FE19A32A76ED395 ON core_studio_customer_space_members (user_id)');
        // One account is on a space once. The form collapses a repeated pick
        // before it sends, and this is what makes that true rather than polite.
        $this->addSql('CREATE UNIQUE INDEX uniq_studio_space_member ON core_studio_customer_space_members (space_id, user_id)');

        $this->addSql('ALTER TABLE core_studio_customer_spaces ADD CONSTRAINT FK_2945F8E69395C3F3 FOREIGN KEY (customer_id) REFERENCES core_customers (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_studio_customer_space_members ADD CONSTRAINT FK_3FE19A3223575340 FOREIGN KEY (space_id) REFERENCES core_studio_customer_spaces (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_studio_customer_space_members ADD CONSTRAINT FK_3FE19A32A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_customer_space_members DROP CONSTRAINT FK_3FE19A3223575340');
        $this->addSql('ALTER TABLE core_studio_customer_space_members DROP CONSTRAINT FK_3FE19A32A76ED395');
        $this->addSql('ALTER TABLE core_studio_customer_spaces DROP CONSTRAINT FK_2945F8E69395C3F3');

        $this->addSql('DROP TABLE core_studio_customer_space_members');
        $this->addSql('DROP TABLE core_studio_customer_spaces');

        $this->addSql('DROP SEQUENCE seq_core_customer_space_member_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_customer_space_id CASCADE');
    }
}
