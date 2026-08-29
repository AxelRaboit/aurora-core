<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Brings markdown notes into core.
 *
 * The module was an external Composer package until it was archived in August;
 * this table is the same shape minus the `agency_id` column, whose module no
 * longer exists. Notes belong to one person and to nobody else.
 *
 * `title` and `content` are TEXT because they hold ciphertext: the entity maps
 * them through `EncryptedTextType`. That has a consequence worth stating here,
 * where the schema lives - neither column can be searched or sorted in SQL, so
 * the two indexes below cover the tree (parent) and the ownership filter (user),
 * which are the only things the database is asked to do quickly.
 *
 * `parent_id` is ON DELETE SET NULL rather than CASCADE: deleting a note lifts
 * its children to the root instead of taking a subtree down with it. Losing a
 * page you wrote because you deleted the folder above it is not a trade anybody
 * offered.
 *
 * Written by hand, as every migration here has to be.
 */
final class Version20260829120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create core_notes_markdown_notes for the Notes module';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_notes_markdown_note_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql(<<<'SQL'
            CREATE TABLE core_notes_markdown_notes (
                id INT NOT NULL,
                user_id INT NOT NULL,
                parent_id INT DEFAULT NULL,
                title TEXT DEFAULT NULL,
                content TEXT DEFAULT NULL,
                tags JSON DEFAULT '[]' NOT NULL,
                position INT DEFAULT 0 NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_notes_markdown_user ON core_notes_markdown_notes (user_id)');
        $this->addSql('CREATE INDEX idx_notes_markdown_parent ON core_notes_markdown_notes (parent_id)');
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD CONSTRAINT FK_C58B8746A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD CONSTRAINT FK_C58B8746727ACA70 FOREIGN KEY (parent_id) REFERENCES core_notes_markdown_notes (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core_notes_markdown_notes');
        $this->addSql('DROP SEQUENCE seq_core_notes_markdown_note_id');
    }
}
