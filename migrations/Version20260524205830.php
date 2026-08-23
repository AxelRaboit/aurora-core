<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * GED Document: add alt + caption columns (image documents - accessibility
 * text and caption, mirroring the Media library fields).
 */
final class Version20260524205830 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'GED Document: add alt + caption columns';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE core_ged_documents ADD alt VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_ged_documents ADD caption TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE core_ged_documents DROP alt');
        $this->addSql('ALTER TABLE core_ged_documents DROP caption');
    }
}
