<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets a publication be looked at before it is published.
 *
 * The front serves `published` and nothing else, which is right, and left nobody
 * able to see a draft: not the author, who published to find out how it rendered,
 * and not the reviewer that `pending_review` exists for, who was asked to approve
 * something they could not read.
 *
 * A short-lived token per post. No label and no revocation date, unlike
 * `core_planning_share_links`: a preview is a glance, not something anybody
 * manages, and an expiry is the whole of its lifetime.
 *
 * `created_by` is `SET NULL` rather than `CASCADE`: deleting an account must not
 * silently delete the preview links its holder handed out. `post_id` is `CASCADE`,
 * because a preview of a deleted post has nothing to show.
 *
 * Written by hand, as every migration here has to be.
 */
final class Version20260824110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add core_post_preview_tokens';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_post_preview_token_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql(<<<'SQL'
            CREATE TABLE core_post_preview_tokens (
              id INT NOT NULL,
              post_id INT NOT NULL,
              created_by_id INT DEFAULT NULL,
              token VARCHAR(64) NOT NULL,
              expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_post_preview_token ON core_post_preview_tokens (token)');
        $this->addSql('CREATE INDEX idx_post_preview_post ON core_post_preview_tokens (post_id)');
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_9A022C7DB03A8386
              ON core_post_preview_tokens (created_by_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE core_post_preview_tokens
              ADD CONSTRAINT FK_9A022C7D4B89032C
              FOREIGN KEY (post_id) REFERENCES core_posts (id)
              ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE core_post_preview_tokens
              ADD CONSTRAINT FK_9A022C7DB03A8386
              FOREIGN KEY (created_by_id) REFERENCES core_users (id)
              ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core_post_preview_tokens');
        $this->addSql('DROP SEQUENCE seq_core_post_preview_token_id');
    }
}
