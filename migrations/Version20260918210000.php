<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A private conversation put away by one person stays whole for the other.
 *
 * The column is carried by the person and not by the room: putting a
 * conversation away is a filing gesture, not a deletion, and the other side
 * still sees it. Null everywhere at first, which is the state of every
 * existing conversation - none has been put away.
 */
final class Version20260918210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A private conversation put away by one person stays whole for the other';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_chat_channel_members ADD hidden_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_chat_channel_members DROP COLUMN hidden_at');
    }
}
