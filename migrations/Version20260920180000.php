<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Writing in the conversation becomes a right of its own.
 *
 * A single right controlled two conversations: commenting on an item, and
 * talking in the space's channel. Ticking a box to allow a remark under a
 * publication therefore also opened the relationship thread.
 *
 * The column is copied from "can comment" rather than set to true: a link
 * already sent out behaves exactly as before, including one that could not
 * comment and must not suddenly be able to talk.
 */
final class Version20260920180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Writing in the space conversation becomes its own right on a link';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_access_links ADD can_chat BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('UPDATE core_studio_space_access_links SET can_chat = can_comment');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_access_links DROP can_chat');
    }
}
