<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 2 of the Media → GED merge - migrate Ecommerce Listing.featured_image_id
 * and ListingCategory.image_id to reference core_ged_documents.
 *
 * Same strategy as Version20260530070621 (Erp Product): copy used media rows
 * into core_ged_documents (NOT EXISTS guard so re-running across modules
 * doesn't duplicate), rewire FK via the unique file_path mapping, swap
 * constraint. Forward-only - original Media rows kept intact.
 */
final class Version20260530071821 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 2: Ecommerce Listing/ListingCategory.image → Document';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_ecommerce_listings DROP CONSTRAINT fk_2e66fdb53569d950');
        $this->addSql('ALTER TABLE core_ecommerce_listing_categories DROP CONSTRAINT fk_3015d4353da5256d');

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
                    SELECT DISTINCT featured_image_id FROM core_ecommerce_listings WHERE featured_image_id IS NOT NULL
                    UNION
                    SELECT DISTINCT image_id FROM core_ecommerce_listing_categories WHERE image_id IS NOT NULL
                )
                  AND NOT EXISTS (SELECT 1 FROM core_ged_documents d WHERE d.file_path = m.path)
            SQL);

        $this->addSql(<<<'SQL'
                UPDATE core_ecommerce_listings l
                SET featured_image_id = d.id
                FROM core_media m
                INNER JOIN core_ged_documents d ON d.file_path = m.path
                WHERE l.featured_image_id = m.id
            SQL);

        $this->addSql(<<<'SQL'
                UPDATE core_ecommerce_listing_categories c
                SET image_id = d.id
                FROM core_media m
                INNER JOIN core_ged_documents d ON d.file_path = m.path
                WHERE c.image_id = m.id
            SQL);

        $this->addSql('ALTER TABLE core_ecommerce_listings ADD CONSTRAINT FK_2E66FDB53569D950 FOREIGN KEY (featured_image_id) REFERENCES core_ged_documents (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_ecommerce_listing_categories ADD CONSTRAINT FK_3015D4353DA5256D FOREIGN KEY (image_id) REFERENCES core_ged_documents (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_ecommerce_listings DROP CONSTRAINT FK_2E66FDB53569D950');
        $this->addSql('ALTER TABLE core_ecommerce_listings ADD CONSTRAINT fk_2e66fdb53569d950 FOREIGN KEY (featured_image_id) REFERENCES core_media (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE core_ecommerce_listing_categories DROP CONSTRAINT FK_3015D4353DA5256D');
        $this->addSql('ALTER TABLE core_ecommerce_listing_categories ADD CONSTRAINT fk_3015d4353da5256d FOREIGN KEY (image_id) REFERENCES core_media (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
