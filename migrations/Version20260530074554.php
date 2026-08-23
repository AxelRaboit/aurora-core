<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 2 of the Media → GED merge - migrate Editorial Post.featured_media_id
 * and PostTranslation.og_image_id to reference core_ged_documents. The
 * JSONB-embedded `mediaId` inside core_post_translations.blocks is NOT
 * migrated here - Phase 3 owns that.
 */
final class Version20260530074554 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 2: Editorial Post.featured + PostTranslation.og_image → Document';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts DROP CONSTRAINT fk_dedc9b1ae2532148');
        $this->addSql('ALTER TABLE core_post_translations DROP CONSTRAINT fk_6a82ae686efcb8b8');

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
                    SELECT DISTINCT featured_media_id FROM core_posts WHERE featured_media_id IS NOT NULL
                    UNION
                    SELECT DISTINCT og_image_id FROM core_post_translations WHERE og_image_id IS NOT NULL
                )
                  AND NOT EXISTS (SELECT 1 FROM core_ged_documents d WHERE d.file_path = m.path)
            SQL);

        $this->addSql(<<<'SQL'
                UPDATE core_posts p
                SET featured_media_id = d.id
                FROM core_media m
                INNER JOIN core_ged_documents d ON d.file_path = m.path
                WHERE p.featured_media_id = m.id
            SQL);

        $this->addSql(<<<'SQL'
                UPDATE core_post_translations t
                SET og_image_id = d.id
                FROM core_media m
                INNER JOIN core_ged_documents d ON d.file_path = m.path
                WHERE t.og_image_id = m.id
            SQL);

        $this->addSql('ALTER TABLE core_posts ADD CONSTRAINT FK_DEDC9B1AE2532148 FOREIGN KEY (featured_media_id) REFERENCES core_ged_documents (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_post_translations ADD CONSTRAINT FK_6A82AE686EFCB8B8 FOREIGN KEY (og_image_id) REFERENCES core_ged_documents (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts DROP CONSTRAINT FK_DEDC9B1AE2532148');
        $this->addSql('ALTER TABLE core_posts ADD CONSTRAINT fk_dedc9b1ae2532148 FOREIGN KEY (featured_media_id) REFERENCES core_media (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE core_post_translations DROP CONSTRAINT FK_6A82AE686EFCB8B8');
        $this->addSql('ALTER TABLE core_post_translations ADD CONSTRAINT fk_6a82ae686efcb8b8 FOREIGN KEY (og_image_id) REFERENCES core_media (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
