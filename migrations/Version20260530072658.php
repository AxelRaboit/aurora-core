<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 2 of the Media → GED merge - migrate Photo Gallery.cover_media_id
 * and GalleryItem.media_id to reference core_ged_documents.
 *
 * GalleryItem.media_id is NOT NULL (every item owns one image) so the data
 * copy + UPDATE must happen before the FK swap can succeed. Same NOT EXISTS
 * guard so cross-module re-use doesn't duplicate Document rows.
 *
 * The DB columns keep their "media_id" name to minimize churn - Phase 5
 * will rename `media_id` → `document_id` (or similar) after Media is gone.
 */
final class Version20260530072658 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 2: Photo Gallery cover + GalleryItem.media → Document';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_photo_galleries DROP CONSTRAINT fk_870cface329a1b2e');
        $this->addSql('ALTER TABLE core_photo_gallery_items DROP CONSTRAINT fk_8b9dd5eaea9fdd75');

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
                WHERE m.id IN (
                    SELECT DISTINCT cover_media_id FROM core_photo_galleries WHERE cover_media_id IS NOT NULL
                    UNION
                    SELECT DISTINCT media_id FROM core_photo_gallery_items
                )
                  AND NOT EXISTS (SELECT 1 FROM core_ged_documents d WHERE d.file_path = m.path)
            SQL);

        $this->addSql(<<<'SQL'
                UPDATE core_photo_galleries g
                SET cover_media_id = d.id
                FROM core_media m
                INNER JOIN core_ged_documents d ON d.file_path = m.path
                WHERE g.cover_media_id = m.id
            SQL);

        $this->addSql(<<<'SQL'
                UPDATE core_photo_gallery_items i
                SET media_id = d.id
                FROM core_media m
                INNER JOIN core_ged_documents d ON d.file_path = m.path
                WHERE i.media_id = m.id
            SQL);

        $this->addSql('ALTER TABLE core_photo_galleries ADD CONSTRAINT FK_870CFACE329A1B2E FOREIGN KEY (cover_media_id) REFERENCES core_ged_documents (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_photo_gallery_items ADD CONSTRAINT FK_8B9DD5EAEA9FDD75 FOREIGN KEY (media_id) REFERENCES core_ged_documents (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_photo_galleries DROP CONSTRAINT FK_870CFACE329A1B2E');
        $this->addSql('ALTER TABLE core_photo_galleries ADD CONSTRAINT fk_870cface329a1b2e FOREIGN KEY (cover_media_id) REFERENCES core_media (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE core_photo_gallery_items DROP CONSTRAINT FK_8B9DD5EAEA9FDD75');
        $this->addSql('ALTER TABLE core_photo_gallery_items ADD CONSTRAINT fk_8b9dd5eaea9fdd75 FOREIGN KEY (media_id) REFERENCES core_media (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
