<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 5 follow-up - actually drop the Media tables.
 *
 * `Version20260530082245` was registered as executed with an empty body
 * (the auto-generated template ran before the DROP statements were
 * written into the file), so the dev DB still carries the orphan
 * `core_media*` tables even though the migration shows as applied.
 * This migration ships the real DROP statements; CASCADE handles the
 * still-present FK constraints from `core_media`.
 *
 * Test DBs created fresh after this migration are unaffected - the
 * IF EXISTS guard makes both runs (existing dev DB + fresh test DB)
 * idempotent.
 */
final class Version20260530083658 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 5 follow-up: drop the Media tables for real';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS core_media_versions CASCADE');
        $this->addSql('DROP TABLE IF EXISTS core_media CASCADE');
        $this->addSql('DROP TABLE IF EXISTS core_media_folders CASCADE');
        $this->addSql('DROP SEQUENCE IF EXISTS seq_core_media_id');
        $this->addSql('DROP SEQUENCE IF EXISTS seq_core_media_folder_id');
        $this->addSql('DROP SEQUENCE IF EXISTS seq_core_media_version_id');
    }

    public function down(Schema $schema): void
    {
        // No restore - recovering Media data requires backups taken before
        // Phase 5 ran.
    }
}
