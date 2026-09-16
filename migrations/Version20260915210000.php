<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The addresses that open one client space without an account.
 *
 * Written by hand, like the two before it: on a development database that
 * predates the removal of Billing, Crm and Ecommerce, `migrations:diff`
 * proposes dropping everything those modules left behind.
 *
 * The selector is unique and the token is not stored at all - only a SHA-256 of
 * it. That is the whole design in two columns: a stolen dump hands somebody the
 * ability to look up rows and not the ability to open a client's content plan.
 */
final class Version20260915210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Signed addresses that open a client space read-only';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_space_access_link_id INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql('CREATE TABLE core_studio_space_access_links (id INT NOT NULL, space_id INT NOT NULL, selector VARCHAR(32) NOT NULL, hashed_token VARCHAR(64) NOT NULL, recipient_email VARCHAR(180) NOT NULL, label VARCHAR(120) DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, first_opened_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AAE387E19692E25D ON core_studio_space_access_links (selector)');
        $this->addSql('CREATE INDEX IDX_AAE387E123575340 ON core_studio_space_access_links (space_id)');

        $this->addSql('ALTER TABLE core_studio_space_access_links ADD CONSTRAINT FK_AAE387E123575340 FOREIGN KEY (space_id) REFERENCES core_studio_customer_spaces (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_access_links DROP CONSTRAINT FK_AAE387E123575340');
        $this->addSql('DROP TABLE core_studio_space_access_links');
        $this->addSql('DROP SEQUENCE seq_core_space_access_link_id CASCADE');
    }
}
