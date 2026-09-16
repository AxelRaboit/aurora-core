<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The files shown on a piece of content.
 *
 * A join table and not a column on the item: the visual half of a post is
 * rarely one file - a carousel, a video and its cover, a press release and the
 * photo that goes with it - and a single `document_id` would have forced the
 * first of those to be modelled as several posts.
 *
 * **No address, no thumbnail, no size.** The row points at a GED document and
 * stops there. Everything drawable is resolved from the document when the page
 * is built, because the address of a file changes when the file behind it is
 * replaced and a copy here would be a second truth going stale in silence.
 *
 * Four foreign keys, three rules, each deliberate:
 *
 * - `item_id` is CASCADE, because a file shown on a card has no life without
 *   the card;
 * - `document_id` is CASCADE too, and that is the asymmetry worth noting. A
 *   post whose `og_image` disappears is still a post, so Editorial's document
 *   relations are SET NULL. This row *is* the statement "this document is on
 *   this item"; with the document gone it asserts nothing, and a null there
 *   would draw as an attachment nobody can open;
 * - `author_user_id` and `author_link_id` are SET NULL, because removing a
 *   person must not remove what they contributed. `author_label` and
 *   `from_client` are written once at upload so "who sent this photo" still has
 *   an answer after an account is deleted or a link revoked.
 *
 * The composite index is on `(item_id, position)` rather than `item_id` alone:
 * every read of this table is one card's files in reading order, and the order
 * is the second half of that query.
 *
 * Taken from `migrations:diff` and kept, which is new. `Version20260916120000`
 * dropped the tables of the extracted modules, so the diff finally proposes the
 * change being made instead of a hundred unrelated drops.
 */
final class Version20260916140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Files attached to a piece of a client space content';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_space_content_attachment_id INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql(<<<'SQL'
            CREATE TABLE core_studio_space_content_attachments (
                id INT NOT NULL,
                item_id INT NOT NULL,
                document_id INT NOT NULL,
                author_user_id INT DEFAULT NULL,
                author_link_id INT DEFAULT NULL,
                position INT DEFAULT 0 NOT NULL,
                author_label VARCHAR(180) NOT NULL,
                from_client BOOLEAN DEFAULT false NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        $this->addSql('CREATE INDEX IDX_F0DD2853126F525E ON core_studio_space_content_attachments (item_id)');
        $this->addSql('CREATE INDEX IDX_F0DD2853C33F7837 ON core_studio_space_content_attachments (document_id)');
        $this->addSql('CREATE INDEX IDX_F0DD2853E2544CD6 ON core_studio_space_content_attachments (author_user_id)');
        $this->addSql('CREATE INDEX IDX_F0DD2853E89E9D32 ON core_studio_space_content_attachments (author_link_id)');
        $this->addSql('CREATE INDEX idx_space_attachment_item ON core_studio_space_content_attachments (item_id, position)');

        $this->addSql('ALTER TABLE core_studio_space_content_attachments ADD CONSTRAINT FK_F0DD2853126F525E FOREIGN KEY (item_id) REFERENCES core_studio_space_content_items (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE core_studio_space_content_attachments ADD CONSTRAINT FK_F0DD2853C33F7837 FOREIGN KEY (document_id) REFERENCES core_ged_documents (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE core_studio_space_content_attachments ADD CONSTRAINT FK_F0DD2853E2544CD6 FOREIGN KEY (author_user_id) REFERENCES core_users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE core_studio_space_content_attachments ADD CONSTRAINT FK_F0DD2853E89E9D32 FOREIGN KEY (author_link_id) REFERENCES core_studio_space_access_links (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core_studio_space_content_attachments');
        $this->addSql('DROP SEQUENCE seq_core_space_content_attachment_id');
    }
}
