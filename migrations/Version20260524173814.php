<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Billing → GED: drop Media coupling, point Invoice + OcrJob to the GED
 * Document. Single file per OCR upload, shared by the OcrJob and the
 * Invoice produced from it. Cf. pattern_self_owned_storage.
 *
 * Existing rows have their FKs nulled - they used to reference Media IDs
 * which won't match the new GED Document IDs. Fixtures repopulate
 * proper values on the next `make demo`.
 */
final class Version20260524173814 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Billing Invoice.document + OcrJob.media → GED Document FK';
    }

    public function up(Schema $schema): void
    {
        // ── core_billing_invoices ────────────────────────────────────────
        $this->addSql('ALTER TABLE core_billing_invoices DROP CONSTRAINT fk_173e636dc33f7837');
        $this->addSql('UPDATE core_billing_invoices SET document_id = NULL');
        $this->addSql('ALTER TABLE core_billing_invoices ADD CONSTRAINT FK_173E636DC33F7837 FOREIGN KEY (document_id) REFERENCES core_ged_documents (id) ON DELETE SET NULL NOT DEFERRABLE');

        // ── core_billing_ocr_jobs ────────────────────────────────────────
        $this->addSql('ALTER TABLE core_billing_ocr_jobs DROP CONSTRAINT fk_ded831f7ea9fdd75');
        $this->addSql('DROP INDEX idx_ded831f7ea9fdd75');
        $this->addSql('DELETE FROM core_billing_ocr_jobs');
        $this->addSql('ALTER TABLE core_billing_ocr_jobs RENAME COLUMN media_id TO document_id');
        $this->addSql('ALTER TABLE core_billing_ocr_jobs ADD CONSTRAINT FK_DED831F7C33F7837 FOREIGN KEY (document_id) REFERENCES core_ged_documents (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_DED831F7C33F7837 ON core_billing_ocr_jobs (document_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_billing_invoices DROP CONSTRAINT FK_173E636DC33F7837');
        $this->addSql('ALTER TABLE core_billing_invoices ADD CONSTRAINT fk_173e636dc33f7837 FOREIGN KEY (document_id) REFERENCES core_media (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE core_billing_ocr_jobs DROP CONSTRAINT FK_DED831F7C33F7837');
        $this->addSql('DROP INDEX IDX_DED831F7C33F7837');
        $this->addSql('ALTER TABLE core_billing_ocr_jobs RENAME COLUMN document_id TO media_id');
        $this->addSql('ALTER TABLE core_billing_ocr_jobs ADD CONSTRAINT fk_ded831f7ea9fdd75 FOREIGN KEY (media_id) REFERENCES core_media (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_ded831f7ea9fdd75 ON core_billing_ocr_jobs (media_id)');
    }
}
