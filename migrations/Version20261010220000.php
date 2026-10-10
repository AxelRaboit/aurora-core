<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * How far each reader has read each room of a client space (10/10/2026).
 *
 * **Seeded as read up to now.** A room nobody has a mark in counts every
 * message as unread, which is right for somebody arriving later and wrong
 * for everybody on the day this ships: every badge of every space would
 * light up with the whole history. So every member of a space and every
 * live link get a mark at the moment of the migration, in every room of
 * their space.
 */
final class Version20261010220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Studio: read marks of the client space conversations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_space_chat_read_marker_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE core_studio_space_chat_read_markers (read_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id INT NOT NULL, channel_id INT NOT NULL, user_id INT DEFAULT NULL, link_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_space_chat_read_user ON core_studio_space_chat_read_markers (channel_id, user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_space_chat_read_link ON core_studio_space_chat_read_markers (channel_id, link_id)');
        $this->addSql('CREATE INDEX IDX_6C188F5872F5A1AA ON core_studio_space_chat_read_markers (channel_id)');
        $this->addSql('CREATE INDEX IDX_6C188F58A76ED395 ON core_studio_space_chat_read_markers (user_id)');
        $this->addSql('CREATE INDEX IDX_6C188F58ADA40271 ON core_studio_space_chat_read_markers (link_id)');
        $this->addSql('ALTER TABLE core_studio_space_chat_read_markers ADD CONSTRAINT FK_6C188F5872F5A1AA FOREIGN KEY (channel_id) REFERENCES core_studio_space_chat_channels (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_studio_space_chat_read_markers ADD CONSTRAINT FK_6C188F58A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_studio_space_chat_read_markers ADD CONSTRAINT FK_6C188F58ADA40271 FOREIGN KEY (link_id) REFERENCES core_studio_space_access_links (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql("INSERT INTO core_studio_space_chat_read_markers (id, channel_id, user_id, read_at)
            SELECT nextval('seq_core_space_chat_read_marker_id'), c.id, m.user_id, NOW()
            FROM core_studio_space_chat_channels c
            JOIN (SELECT DISTINCT space_id, user_id FROM core_studio_customer_space_members) m ON m.space_id = c.space_id");
        $this->addSql("INSERT INTO core_studio_space_chat_read_markers (id, channel_id, link_id, read_at)
            SELECT nextval('seq_core_space_chat_read_marker_id'), c.id, l.id, NOW()
            FROM core_studio_space_chat_channels c
            JOIN core_studio_space_access_links l ON l.space_id = c.space_id
            WHERE l.preview_of_id IS NULL AND l.revoked_at IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core_studio_space_chat_read_markers');
        $this->addSql('DROP SEQUENCE seq_core_space_chat_read_marker_id');
    }
}
