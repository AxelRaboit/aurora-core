<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 5 of the Media → GED merge - drop the Media library tables.
 * Every consumer has been migrated to Documents in phases 2 → 4, so the
 * media tables can now go. Forward-only; restoring Media would require
 * rolling back Phase 2 + 3 + 5 together.
 */
final class Version20260530082245 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 5: drop core_media, core_media_folders, core_media_versions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS core_media_versions');
        $this->addSql('DROP TABLE IF EXISTS core_media');
        $this->addSql('DROP TABLE IF EXISTS core_media_folders');
        $this->addSql('DROP SEQUENCE IF EXISTS seq_core_media_id');
        $this->addSql('DROP SEQUENCE IF EXISTS seq_core_media_folder_id');
        $this->addSql('DROP SEQUENCE IF EXISTS seq_core_media_version_id');
    }

    public function down(Schema $schema): void
    {
        // No restore - re-creating the empty schema without the original
        // data would be misleading. Recover from backup if needed.
    }
}
