<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Override;

/**
 * The categories of Studio deliverables: audit, strategy, proposal.
 *
 * A table of their own, and an optional category per deliverable. Deleting a
 * category leaves its deliverables "uncategorised". No existing deliverable
 * gets one.
 */
final class Version20261004160000 extends AbstractMigration
{
    #[Override]
    public function getDescription(): string
    {
        return 'Categories for Studio deliverables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_deliverable_category_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE core_studio_deliverable_categories (id INT NOT NULL, name VARCHAR(100) NOT NULL, color VARCHAR(7) DEFAULT NULL, position INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE core_studio_deliverables ADD category_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE core_studio_deliverables ADD CONSTRAINT FK_154B06C312469DE2 FOREIGN KEY (category_id) REFERENCES core_studio_deliverable_categories (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_deliverable_category ON core_studio_deliverables (category_id)');
    }

    #[Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_deliverables DROP CONSTRAINT FK_154B06C312469DE2');
        $this->addSql('DROP INDEX idx_deliverable_category');
        $this->addSql('ALTER TABLE core_studio_deliverables DROP category_id');
        $this->addSql('DROP TABLE core_studio_deliverable_categories');
        $this->addSql('DROP SEQUENCE seq_core_deliverable_category_id');
    }
}
