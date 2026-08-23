<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Project task attachments: Media → GED Document. Drops the old
 * core_project_task_attachments join table (no production data - feature
 * was UI-incomplete, no fixtures used it) and recreates it as
 * core_project_task_documents pointing at core_ged_documents.
 * Cf. pattern_self_owned_storage.
 */
final class Version20260524180700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ProjectTask.attachments → GED Document FK';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE core_project_task_documents (task_id INT NOT NULL, document_id INT NOT NULL, PRIMARY KEY (task_id, document_id))');
        $this->addSql('CREATE INDEX IDX_6CA656098DB60186 ON core_project_task_documents (task_id)');
        $this->addSql('CREATE INDEX IDX_6CA65609C33F7837 ON core_project_task_documents (document_id)');
        $this->addSql('ALTER TABLE core_project_task_documents ADD CONSTRAINT FK_6CA656098DB60186 FOREIGN KEY (task_id) REFERENCES core_project_tasks (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_project_task_documents ADD CONSTRAINT FK_6CA65609C33F7837 FOREIGN KEY (document_id) REFERENCES core_ged_documents (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_project_task_attachments DROP CONSTRAINT fk_595f791b8db60186');
        $this->addSql('ALTER TABLE core_project_task_attachments DROP CONSTRAINT fk_595f791bea9fdd75');
        $this->addSql('DROP TABLE core_project_task_attachments');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE core_project_task_attachments (task_id INT NOT NULL, media_id INT NOT NULL, PRIMARY KEY (task_id, media_id))');
        $this->addSql('CREATE INDEX idx_595f791b8db60186 ON core_project_task_attachments (task_id)');
        $this->addSql('CREATE INDEX idx_595f791bea9fdd75 ON core_project_task_attachments (media_id)');
        $this->addSql('ALTER TABLE core_project_task_attachments ADD CONSTRAINT fk_595f791b8db60186 FOREIGN KEY (task_id) REFERENCES core_project_tasks (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE core_project_task_attachments ADD CONSTRAINT fk_595f791bea9fdd75 FOREIGN KEY (media_id) REFERENCES core_media (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE core_project_task_documents DROP CONSTRAINT FK_6CA656098DB60186');
        $this->addSql('ALTER TABLE core_project_task_documents DROP CONSTRAINT FK_6CA65609C33F7837');
        $this->addSql('DROP TABLE core_project_task_documents');
    }
}
