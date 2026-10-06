<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An access link can be the preview of another one.
 *
 * Null everywhere to begin with: all existing links are real links. The
 * cascade is intended, a preview does not outlive what it shows.
 */
final class Version20260920120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An access link can be the preview of another';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_access_links ADD preview_of_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE core_studio_space_access_links ADD CONSTRAINT fk_space_access_preview_of FOREIGN KEY (preview_of_id) REFERENCES core_studio_space_access_links (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_space_access_preview_of ON core_studio_space_access_links (preview_of_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_access_links DROP CONSTRAINT fk_space_access_preview_of');
        $this->addSql('DROP INDEX idx_space_access_preview_of');
        $this->addSql('ALTER TABLE core_studio_space_access_links DROP preview_of_id');
    }
}
