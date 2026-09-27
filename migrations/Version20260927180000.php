<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Indexes on the two paths a served file is looked up by.
 *
 * Every picture served from R2 finds its document by `file_path`, exactly or
 * as `LIKE 'dir/stem.%'` for a rendition, and nothing indexed the column: a
 * sequential scan of the library per image on the page.
 *
 * `text_pattern_ops` because a plain B-tree only serves a `LIKE` prefix when
 * the database collation is `C`, which nobody can promise of the server the
 * bundle is installed on. The operator class serves equality too. The entity
 * declares the same names and columns; Doctrine does not compare operator
 * classes, so its diff sees nothing to change.
 */
final class Version20260927180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'GED: pattern indexes on the stored file and thumbnail paths';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_ged_document_file_path ON core_ged_documents (file_path text_pattern_ops)');
        $this->addSql('CREATE INDEX idx_ged_document_thumbnail_path ON core_ged_documents (thumbnail_path text_pattern_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_ged_document_file_path');
        $this->addSql('DROP INDEX idx_ged_document_thumbnail_path');
    }
}
