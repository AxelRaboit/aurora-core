<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Deliverables carry a format, a "template" flag and a customer.
 *
 * The format (page or slides) prepares the arrival of Studio presentations
 * among deliverables: every existing deliverable is a page. The "template"
 * flag and the customer only apply to a Studio deliverable, without a space;
 * no existing deliverable is a template or has a customer.
 *
 * Written by hand: the generated diff also renamed unrelated indexes.
 */
final class Version20261006120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Studio deliverables: format (page|slides), template flag and customer';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE core_studio_deliverables ADD format VARCHAR(16) DEFAULT 'page' NOT NULL");
        $this->addSql('ALTER TABLE core_studio_deliverables ADD template BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE core_studio_deliverables ADD customer_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE core_studio_deliverables ADD CONSTRAINT FK_154B06C39395C3F3 FOREIGN KEY (customer_id) REFERENCES core_customers (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_deliverable_customer ON core_studio_deliverables (customer_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_deliverables DROP CONSTRAINT FK_154B06C39395C3F3');
        $this->addSql('DROP INDEX idx_deliverable_customer');
        $this->addSql('ALTER TABLE core_studio_deliverables DROP customer_id');
        $this->addSql('ALTER TABLE core_studio_deliverables DROP template');
        $this->addSql('ALTER TABLE core_studio_deliverables DROP format');
    }
}
