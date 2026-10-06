<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The trash of client spaces and of their contents: a trash date, null while
 * alive. Existing spaces and contents stay alive.
 */
final class Version20261006210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Client spaces and their contents: a trash date, null while alive';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_customer_spaces ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_customer_space_deleted_at ON core_studio_customer_spaces (deleted_at)');
        $this->addSql('ALTER TABLE core_studio_space_content_items ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_space_content_item_deleted_at ON core_studio_space_content_items (deleted_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_space_content_item_deleted_at');
        $this->addSql('ALTER TABLE core_studio_space_content_items DROP deleted_at');
        $this->addSql('DROP INDEX idx_customer_space_deleted_at');
        $this->addSql('ALTER TABLE core_studio_customer_spaces DROP deleted_at');
    }
}
