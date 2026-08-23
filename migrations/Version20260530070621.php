<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 2 of the Media → GED merge - migrate Erp Product.image_id to
 * reference core_ged_documents instead of core_media. Plan:
 * docs/aurora-core/todo/media-ged-merge.md.
 *
 * Strategy:
 *   1. Drop the old FK to core_media.
 *   2. Copy every USED media row (referenced by at least one Product)
 *      into core_ged_documents, preserving file_path so the URL doesn't
 *      break. `reference` is intentionally NULLed on copies to avoid
 *      colliding with future GED reference assignments.
 *   3. Rewire each Product.image_id to the new document id via the
 *      unique `file_path` mapping (path is `<slug>-<uniqid>.<ext>` so
 *      it's globally unique by construction).
 *   4. Add the new FK to core_ged_documents.
 *
 * Side effect: the original core_media rows are NOT deleted - they stay
 * available so the Media library keeps working during Phase 2 (other
 * consumers may still reference them). Phase 5 drops core_media entirely
 * once every consumer has migrated.
 */
final class Version20260530070621 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 2: Erp Product.image → Document (data migration + FK swap)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_erp_products DROP CONSTRAINT fk_4de001913da5256d');

        $this->addSql(<<<'SQL'
                INSERT INTO core_ged_documents (
                    id,
                    title, description, status,
                    file_path, file_name, original_name, mime_type, size,
                    width, height, thumbnail_path,
                    alt, caption,
                    focal_x, focal_y, variants,
                    created_at, updated_at
                )
                SELECT
                    nextval('seq_core_ged_document_id'),
                    COALESCE(NULLIF(m.original_name, ''), 'Untitled') AS title,
                    NULL AS description,
                    'published' AS status,
                    m.path AS file_path,
                    m.filename AS file_name,
                    m.original_name,
                    m.mime_type,
                    m.size,
                    m.width,
                    m.height,
                    NULL AS thumbnail_path,
                    m.alt,
                    m.caption,
                    m.focal_x,
                    m.focal_y,
                    m.variants,
                    m.created_at,
                    m.updated_at
                FROM core_media m
                WHERE m.id IN (SELECT DISTINCT image_id FROM core_erp_products WHERE image_id IS NOT NULL)
                  AND NOT EXISTS (SELECT 1 FROM core_ged_documents d WHERE d.file_path = m.path)
            SQL);

        $this->addSql(<<<'SQL'
                UPDATE core_erp_products p
                SET image_id = d.id
                FROM core_media m
                INNER JOIN core_ged_documents d ON d.file_path = m.path
                WHERE p.image_id = m.id
            SQL);

        $this->addSql('ALTER TABLE core_erp_products ADD CONSTRAINT FK_4DE001913DA5256D FOREIGN KEY (image_id) REFERENCES core_ged_documents (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // No data rollback - Phase 2 migrations are forward-only. The
        // original Media rows still exist; restoring the FK would orphan
        // the copied Document rows but wouldn't lose Media data.
        $this->addSql('ALTER TABLE core_erp_products DROP CONSTRAINT FK_4DE001913DA5256D');
        $this->addSql('ALTER TABLE core_erp_products ADD CONSTRAINT fk_4de001913da5256d FOREIGN KEY (image_id) REFERENCES core_media (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
