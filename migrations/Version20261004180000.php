<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Override;

/**
 * One image per deliverable, taken from the media library: its card thumbnail.
 *
 * Optional; deleting the document removes it from the deliverable. No
 * existing deliverable gets one.
 */
final class Version20261004180000 extends AbstractMigration
{
    #[Override]
    public function getDescription(): string
    {
        return 'An optional image for each deliverable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_deliverables ADD thumbnail_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE core_studio_deliverables ADD CONSTRAINT FK_154B06C3FDFF2E92 FOREIGN KEY (thumbnail_id) REFERENCES core_ged_documents (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_deliverable_thumbnail ON core_studio_deliverables (thumbnail_id)');
    }

    #[Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_deliverables DROP CONSTRAINT FK_154B06C3FDFF2E92');
        $this->addSql('DROP INDEX idx_deliverable_thumbnail');
        $this->addSql('ALTER TABLE core_studio_deliverables DROP thumbnail_id');
    }
}
