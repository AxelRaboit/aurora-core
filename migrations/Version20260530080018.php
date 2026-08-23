<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 2 of the Media → GED merge - migrate the branding setting values
 * (logo_media_id, favicon_media_id, seo_default_og_image) and the active
 * theme's `header_logo_media_id` config from Media IDs to Document IDs.
 *
 * Settings keep their `*_media_id` key name so the keep-stable-key rule
 * holds - the **value** semantics flip to "Document id". Theme.config is
 * a JSONB column; we walk every row and overwrite the relevant key with
 * the resolved document id.
 */
final class Version20260530080018 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 2: branding setting IDs (logo/favicon/og:image + theme header logo) → Document IDs';
    }

    public function up(Schema $schema): void
    {
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
                WHERE m.id::text IN (
                    SELECT value FROM core_settings WHERE setting_key IN ('logo_media_id', 'favicon_media_id', 'seo_default_og_image') AND value IS NOT NULL AND value <> ''
                    UNION
                    SELECT (config->>'header_logo_media_id') FROM core_themes WHERE config->>'header_logo_media_id' IS NOT NULL AND config->>'header_logo_media_id' <> ''
                )
                  AND NOT EXISTS (SELECT 1 FROM core_ged_documents d WHERE d.file_path = m.path)
            SQL);

        $this->addSql(<<<'SQL'
                UPDATE core_settings s
                SET value = d.id::text
                FROM core_media m
                INNER JOIN core_ged_documents d ON d.file_path = m.path
                WHERE s.setting_key IN ('logo_media_id', 'favicon_media_id', 'seo_default_og_image')
                  AND s.value = m.id::text
            SQL);

        $this->addSql(<<<'SQL'
                UPDATE core_themes t
                SET config = jsonb_set(config::jsonb, '{header_logo_media_id}', to_jsonb(d.id::text))::json
                FROM core_media m
                INNER JOIN core_ged_documents d ON d.file_path = m.path
                WHERE config->>'header_logo_media_id' = m.id::text
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Forward-only - branding migrations don't roll back. The original
        // core_media rows still exist, so a manual repoint is possible if
        // needed.
    }
}
