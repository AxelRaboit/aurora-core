<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A space's files, those that are on no card.
 *
 * Written by hand, like the ones before it: `migrations:diff` on this
 * development database also offers to rename two dozen indexes it did not
 * create.
 *
 * Its own table rather than an optional card on the attachments: an attachment
 * row says "this document is on this card" and breaks on both sides, a `null`
 * would make it say two things.
 *
 * Both keys cascade. The space because its files do not exist without it; the
 * document because this row is the attachment itself, and a null document
 * would show as a file nobody can open. The media library's usage registry
 * warns before it comes to that.
 */
final class Version20260917200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Files that belong to a client space and to no card';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_space_file_id INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql('CREATE TABLE core_studio_space_files (id INT NOT NULL, space_id INT NOT NULL, document_id INT NOT NULL, author_user_id INT DEFAULT NULL, author_link_id INT DEFAULT NULL, author_label VARCHAR(180) NOT NULL, from_client BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');

        $this->addSql('CREATE INDEX idx_space_file_space_created ON core_studio_space_files (space_id, created_at)');
        $this->addSql('CREATE INDEX IDX_9F2D4E1523575340 ON core_studio_space_files (space_id)');
        $this->addSql('CREATE INDEX IDX_9F2D4E15C33F7837 ON core_studio_space_files (document_id)');
        $this->addSql('CREATE INDEX IDX_9F2D4E15C5B69EC ON core_studio_space_files (author_user_id)');
        $this->addSql('CREATE INDEX IDX_9F2D4E15A4B7B09E ON core_studio_space_files (author_link_id)');

        $this->addSql('ALTER TABLE core_studio_space_files ADD CONSTRAINT FK_9F2D4E1523575340 FOREIGN KEY (space_id) REFERENCES core_studio_customer_spaces (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_studio_space_files ADD CONSTRAINT FK_9F2D4E15C33F7837 FOREIGN KEY (document_id) REFERENCES core_ged_documents (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_studio_space_files ADD CONSTRAINT FK_9F2D4E15C5B69EC FOREIGN KEY (author_user_id) REFERENCES core_users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_studio_space_files ADD CONSTRAINT FK_9F2D4E15A4B7B09E FOREIGN KEY (author_link_id) REFERENCES core_studio_space_access_links (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_files DROP CONSTRAINT FK_9F2D4E15A4B7B09E');
        $this->addSql('ALTER TABLE core_studio_space_files DROP CONSTRAINT FK_9F2D4E15C5B69EC');
        $this->addSql('ALTER TABLE core_studio_space_files DROP CONSTRAINT FK_9F2D4E15C33F7837');
        $this->addSql('ALTER TABLE core_studio_space_files DROP CONSTRAINT FK_9F2D4E1523575340');
        $this->addSql('DROP TABLE core_studio_space_files');
        $this->addSql('DROP SEQUENCE seq_core_space_file_id CASCADE');
    }
}
