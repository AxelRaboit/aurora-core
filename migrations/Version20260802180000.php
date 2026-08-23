<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Indexes the two columns every comment query filters on together.
 *
 * Both callers ask the same question - one post's comments in one status:
 * the public thread wants the approved ones, the moderation queue wants the
 * pending ones. The table had an index on `post_id` alone, so the status
 * filter fell to a scan of every comment on the post.
 */
final class Version20260802180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index core_comments on (post_id, status)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_comment_post_status ON core_comments (post_id, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_comment_post_status');
    }
}
