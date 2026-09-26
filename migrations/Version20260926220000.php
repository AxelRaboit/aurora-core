<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Document families and kept documents. `original_id` points an alternate at
 * the document it is declined from (SET NULL: deleting the original leaves
 * its alternates standing), `alternate_label` names what sets it apart, and
 * `kept` marks a document as unused on purpose.
 */
final class Version20260926220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add kept, original_id and alternate_label to core_ged_documents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_ged_documents ADD kept BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE core_ged_documents ADD alternate_label VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_ged_documents ADD original_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE core_ged_documents ADD CONSTRAINT FK_A80B359A108B7592 FOREIGN KEY (original_id) REFERENCES core_ged_documents (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_A80B359A108B7592 ON core_ged_documents (original_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_ged_documents DROP CONSTRAINT FK_A80B359A108B7592');
        $this->addSql('DROP INDEX IDX_A80B359A108B7592');
        $this->addSql('ALTER TABLE core_ged_documents DROP kept');
        $this->addSql('ALTER TABLE core_ged_documents DROP alternate_label');
        $this->addSql('ALTER TABLE core_ged_documents DROP original_id');
    }
}
