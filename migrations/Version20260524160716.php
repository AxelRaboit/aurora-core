<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * GED documents + versions: drop the Media coupling, switch to self-owned
 * file storage. Each row now carries its own filePath / fileName /
 * originalName / mimeType / size - files live under
 * `var/uploads/ged/Y/m/<slug>-<uniq>.<ext>` served by the catch-all
 * `/uploads/{path}` route.
 *
 * Hand-edited (the auto-generated diff incorrectly tried to rename
 * `file_id` to `size`; we actually want to drop the FK and add 5 new
 * columns from scratch).
 */
final class Version20260524160716 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'GED Document + DocumentVersion: drop Media coupling, add self-owned file fields';
    }

    public function up(Schema $schema): void
    {
        // ── core_ged_documents ──────────────────────────────────────────
        $this->addSql('ALTER TABLE core_ged_documents DROP CONSTRAINT fk_a80b359a93cb796c');
        $this->addSql('DROP INDEX idx_a80b359a93cb796c');
        $this->addSql('ALTER TABLE core_ged_documents DROP file_id');
        $this->addSql('ALTER TABLE core_ged_documents ADD file_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_ged_documents ADD file_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_ged_documents ADD original_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_ged_documents ADD mime_type VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_ged_documents ADD size INT DEFAULT NULL');

        // ── core_ged_document_versions ──────────────────────────────────
        $this->addSql('ALTER TABLE core_ged_document_versions DROP CONSTRAINT fk_1392373d93cb796c');
        $this->addSql('DROP INDEX idx_1392373d93cb796c');
        $this->addSql('ALTER TABLE core_ged_document_versions DROP file_id');
        $this->addSql('ALTER TABLE core_ged_document_versions ADD file_path VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE core_ged_document_versions ADD file_name VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE core_ged_document_versions ADD original_name VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE core_ged_document_versions ADD mime_type VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE core_ged_document_versions ADD size INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_ged_document_versions DROP file_path');
        $this->addSql('ALTER TABLE core_ged_document_versions DROP file_name');
        $this->addSql('ALTER TABLE core_ged_document_versions DROP original_name');
        $this->addSql('ALTER TABLE core_ged_document_versions DROP mime_type');
        $this->addSql('ALTER TABLE core_ged_document_versions DROP size');
        $this->addSql('ALTER TABLE core_ged_document_versions ADD file_id INT NOT NULL');
        $this->addSql('ALTER TABLE core_ged_document_versions ADD CONSTRAINT fk_1392373d93cb796c FOREIGN KEY (file_id) REFERENCES core_media (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_1392373d93cb796c ON core_ged_document_versions (file_id)');

        $this->addSql('ALTER TABLE core_ged_documents DROP file_path');
        $this->addSql('ALTER TABLE core_ged_documents DROP file_name');
        $this->addSql('ALTER TABLE core_ged_documents DROP original_name');
        $this->addSql('ALTER TABLE core_ged_documents DROP mime_type');
        $this->addSql('ALTER TABLE core_ged_documents DROP size');
        $this->addSql('ALTER TABLE core_ged_documents ADD file_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE core_ged_documents ADD CONSTRAINT fk_a80b359a93cb796c FOREIGN KEY (file_id) REFERENCES core_media (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_a80b359a93cb796c ON core_ged_documents (file_id)');
    }
}
