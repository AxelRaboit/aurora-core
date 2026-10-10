<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What the studio did, told to the client (10/10/2026).
 *
 * One table of notices, one row per piece of news and per access link, and
 * the space's choice of mailing them. Every existing space is « off »:
 * nothing is sent to a client until somebody chooses to.
 */
final class Version20261010210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Studio: client notices and the space client digest setting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_client_notice_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE core_studio_client_notices (type VARCHAR(30) NOT NULL, subject VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, emailed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id INT NOT NULL, link_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_client_notice_link_pending ON core_studio_client_notices (link_id, seen_at, emailed_at)');
        $this->addSql('CREATE INDEX IDX_480D9F82ADA40271 ON core_studio_client_notices (link_id)');
        $this->addSql('ALTER TABLE core_studio_client_notices ADD CONSTRAINT FK_480D9F82ADA40271 FOREIGN KEY (link_id) REFERENCES core_studio_space_access_links (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql("ALTER TABLE core_studio_customer_spaces ADD client_digest VARCHAR(20) DEFAULT 'off' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_customer_spaces DROP client_digest');
        $this->addSql('DROP TABLE core_studio_client_notices');
        $this->addSql('DROP SEQUENCE seq_core_client_notice_id');
    }
}
