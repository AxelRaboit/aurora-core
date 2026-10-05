<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La corbeille des livrables : une date de mise à la corbeille, nulle tant que
 * le livrable est vivant. Les livrables existants le restent.
 */
final class Version20261005120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Deliverables: a trash date, null while alive';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_deliverables ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_deliverable_deleted_at ON core_studio_deliverables (deleted_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_deliverable_deleted_at');
        $this->addSql('ALTER TABLE core_studio_deliverables DROP deleted_at');
    }
}
