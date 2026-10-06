<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A customer's « user account » goes: the field was shown and saved, and
 * nothing anywhere read it back. The column, its index and its foreign key are
 * dropped; no account is touched.
 */
final class Version20261006220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customers: drop the user account column, which nothing read';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_customers DROP CONSTRAINT IF EXISTS FK_2D337E05A76ED395');
        $this->addSql('DROP INDEX IF EXISTS IDX_2D337E05A76ED395');
        $this->addSql('ALTER TABLE core_customers DROP COLUMN IF EXISTS user_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_customers ADD user_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_2D337E05A76ED395 ON core_customers (user_id)');
        $this->addSql('ALTER TABLE core_customers ADD CONSTRAINT FK_2D337E05A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }
}
