<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A space's board: its steps, and the content that moves through them.
 *
 * Written by hand for the reason the previous one gives: on a development
 * database that predates the removal of Billing, Crm and Ecommerce,
 * `migrations:diff` proposes dropping everything those modules left behind.
 * Only the statements naming the two new tables are kept.
 *
 * `CASCADE` from a card to its column, where `RESTRICT` would read as the
 * safer choice and is a trap: deleting a space cascades to its columns and to
 * its cards at once, in no guaranteed order, so a restriction here would refuse
 * a deletion the user is entitled to. The rule that a column holding cards is
 * not deleted lives in the Manager, which can tell the two cases apart.
 */
final class Version20260915190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The board of a client space: steps and content';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_space_content_column_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_space_content_item_id INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql('CREATE TABLE core_studio_space_content_columns (id INT NOT NULL, space_id INT NOT NULL, name VARCHAR(100) NOT NULL, position INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5DAD42FB23575340 ON core_studio_space_content_columns (space_id)');

        $this->addSql('CREATE TABLE core_studio_space_content_items (id INT NOT NULL, space_id INT NOT NULL, column_id INT NOT NULL, title VARCHAR(255) NOT NULL, body TEXT DEFAULT NULL, scheduled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, position INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3C4C033323575340 ON core_studio_space_content_items (space_id)');
        $this->addSql('CREATE INDEX IDX_3C4C0333BE8E8ED5 ON core_studio_space_content_items (column_id)');

        $this->addSql('ALTER TABLE core_studio_space_content_columns ADD CONSTRAINT FK_5DAD42FB23575340 FOREIGN KEY (space_id) REFERENCES core_studio_customer_spaces (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_studio_space_content_items ADD CONSTRAINT FK_3C4C033323575340 FOREIGN KEY (space_id) REFERENCES core_studio_customer_spaces (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_studio_space_content_items ADD CONSTRAINT FK_3C4C0333BE8E8ED5 FOREIGN KEY (column_id) REFERENCES core_studio_space_content_columns (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_content_items DROP CONSTRAINT FK_3C4C0333BE8E8ED5');
        $this->addSql('ALTER TABLE core_studio_space_content_items DROP CONSTRAINT FK_3C4C033323575340');
        $this->addSql('ALTER TABLE core_studio_space_content_columns DROP CONSTRAINT FK_5DAD42FB23575340');

        $this->addSql('DROP TABLE core_studio_space_content_items');
        $this->addSql('DROP TABLE core_studio_space_content_columns');

        $this->addSql('DROP SEQUENCE seq_core_space_content_item_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_space_content_column_id CASCADE');
    }
}
