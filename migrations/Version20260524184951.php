<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * GED Document thumbnails: add nullable thumbnail_path column for the
 * server-side rendered preview of opaque formats (PDFs). Native image
 * MIMEs keep `thumbnail_path` null - the serializer falls back on the
 * source file itself.
 *
 * Existing rows ship with NULL; run `aurora:ged:thumbnails:generate` to
 * backfill thumbnails for PDFs already in the library.
 */
final class Version20260524184951 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'GED Document: add thumbnail_path column';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE core_ged_documents ADD thumbnail_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE core_ged_documents DROP thumbnail_path');
    }
}
