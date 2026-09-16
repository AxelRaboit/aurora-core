<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A thread on each piece of content, and the note that becomes its first message.
 *
 * Written by hand, like the ones before it: `migrations:diff` on this
 * development database proposes dropping every table the removed modules left.
 *
 * **The notes are carried over rather than dropped.** `approval_note` held what
 * a client wrote, which is the one thing here nobody can recreate - and the
 * reason for the move is precisely that the column was being cleared under
 * them. Each note becomes a message signed by the link that answered, dated at
 * the moment of the answer, so a thread opened after this migration reads as it
 * would have if it had always existed.
 *
 * The copy runs before the column goes, and `down()` puts the column back but
 * not its contents: a rollback that re-derived notes from messages would have
 * to guess which message was one.
 */
final class Version20260915240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A shared thread on each piece of content';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_space_content_comment_id INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql('CREATE TABLE core_studio_space_content_comments (id INT NOT NULL, item_id INT NOT NULL, author_user_id INT DEFAULT NULL, author_link_id INT DEFAULT NULL, body TEXT NOT NULL, author_label VARCHAR(180) NOT NULL, from_client BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2005F813126F525E ON core_studio_space_content_comments (item_id)');
        $this->addSql('CREATE INDEX IDX_2005F813E2544CD6 ON core_studio_space_content_comments (author_user_id)');
        $this->addSql('CREATE INDEX IDX_2005F813E89E9D32 ON core_studio_space_content_comments (author_link_id)');

        $this->addSql('ALTER TABLE core_studio_space_content_comments ADD CONSTRAINT FK_2005F813126F525E FOREIGN KEY (item_id) REFERENCES core_studio_space_content_items (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_studio_space_content_comments ADD CONSTRAINT FK_2005F813E2544CD6 FOREIGN KEY (author_user_id) REFERENCES core_users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_studio_space_content_comments ADD CONSTRAINT FK_2005F813E89E9D32 FOREIGN KEY (author_link_id) REFERENCES core_studio_space_access_links (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql('ALTER TABLE core_studio_space_access_links ADD can_comment BOOLEAN DEFAULT true NOT NULL');

        // Every note that exists becomes the message it always was. The author
        // is the link that answered and the date is the answer's, so the thread
        // reads in the order things actually happened.
        $this->addSql(<<<'SQL'
            INSERT INTO core_studio_space_content_comments
                (id, item_id, author_user_id, author_link_id, body, author_label, from_client, created_at)
            SELECT
                nextval('seq_core_space_content_comment_id'),
                i.id,
                NULL,
                i.approval_by_link_id,
                i.approval_note,
                COALESCE(l.recipient_email, ''),
                true,
                COALESCE(i.approval_at, i.created_at)
            FROM core_studio_space_content_items i
            LEFT JOIN core_studio_space_access_links l ON l.id = i.approval_by_link_id
            WHERE i.approval_note IS NOT NULL AND i.approval_note <> ''
            SQL);

        $this->addSql('ALTER TABLE core_studio_space_content_items DROP approval_note');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_content_items ADD approval_note TEXT DEFAULT NULL');

        $this->addSql('ALTER TABLE core_studio_space_access_links DROP can_comment');

        $this->addSql('ALTER TABLE core_studio_space_content_comments DROP CONSTRAINT FK_2005F813E89E9D32');
        $this->addSql('ALTER TABLE core_studio_space_content_comments DROP CONSTRAINT FK_2005F813E2544CD6');
        $this->addSql('ALTER TABLE core_studio_space_content_comments DROP CONSTRAINT FK_2005F813126F525E');
        $this->addSql('DROP TABLE core_studio_space_content_comments');
        $this->addSql('DROP SEQUENCE seq_core_space_content_comment_id CASCADE');
    }
}
