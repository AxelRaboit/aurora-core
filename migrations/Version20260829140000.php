<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Addresses that open one note without an account.
 *
 * Two shapes live in this one table. A row with `recipient_email` null is the
 * "copy the link" share; a row with an address is personal, was mailed to that
 * person, and can be revoked without touching anybody else's. Splitting them
 * would have duplicated the token, the expiry and the revocation to express one
 * nullable column.
 *
 * `token` is unique so a collision fails loudly on insert instead of handing
 * one reader another reader's note. `note_id` is ON DELETE CASCADE: deleting a
 * note has to take its addresses with it, or a revocation would be the only
 * thing standing between a deleted note and whoever still holds the link.
 *
 * No `can_write` column, deliberately - there is no write path for a guest, and
 * a switch that does nothing is worse than an absent one. See
 * `project_notes_share_link_read_only`.
 *
 * Written by hand, as every migration here has to be.
 */
final class Version20260829140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create core_notes_markdown_share_links';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_notes_markdown_share_link_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql(<<<'SQL'
            CREATE TABLE core_notes_markdown_share_links (
                id INT NOT NULL,
                note_id INT NOT NULL,
                token VARCHAR(64) NOT NULL,
                include_descendants BOOLEAN DEFAULT false NOT NULL,
                recipient_email VARCHAR(180) DEFAULT NULL,
                label VARCHAR(120) DEFAULT '' NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3FD8A9AB5F37A13B ON core_notes_markdown_share_links (token)');
        $this->addSql('CREATE INDEX idx_notes_share_link_note ON core_notes_markdown_share_links (note_id)');
        $this->addSql('ALTER TABLE core_notes_markdown_share_links ADD CONSTRAINT FK_3FD8A9AB26ED0855 FOREIGN KEY (note_id) REFERENCES core_notes_markdown_notes (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core_notes_markdown_share_links');
        $this->addSql('DROP SEQUENCE seq_core_notes_markdown_share_link_id');
    }
}
