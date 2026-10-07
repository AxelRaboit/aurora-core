<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Channels in a space conversation, and the people in them.
 *
 * Written by hand like the ones before it: `migrations:diff` on this
 * development database also offers to rename two dozen indexes it did not
 * create.
 *
 * **Messages are attached in three steps, and that is mandatory.**
 * The column arrives nullable, each space gets its main channel, all its
 * messages are moved into it, and the column becomes not null once none is
 * left without a channel. The reverse order would reject the column on any
 * database that already holds a conversation, which means production.
 *
 * The main channel is created for **every** space, even a silent one: the
 * conversation opens on a channel, and a space without a channel would force
 * every screen to know how to make one.
 */
final class Version20260918180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Channels in a space conversation, and who is in them';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_space_chat_channel_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_space_chat_channel_member_id INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql('CREATE TABLE core_studio_space_chat_channels (id INT NOT NULL, space_id INT NOT NULL, name VARCHAR(120) NOT NULL, kind VARCHAR(20) DEFAULT \'topic\' NOT NULL, position INT DEFAULT 0 NOT NULL, open_to_client BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_space_chat_channel_space ON core_studio_space_chat_channels (space_id, position)');
        $this->addSql('CREATE INDEX IDX_C1A7F4D123575340 ON core_studio_space_chat_channels (space_id)');
        $this->addSql('ALTER TABLE core_studio_space_chat_channels ADD CONSTRAINT FK_C1A7F4D123575340 FOREIGN KEY (space_id) REFERENCES core_studio_customer_spaces (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE core_studio_space_chat_channel_members (id INT NOT NULL, channel_id INT NOT NULL, user_id INT DEFAULT NULL, link_id INT DEFAULT NULL, label VARCHAR(180) NOT NULL, from_client BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_space_chat_member_channel ON core_studio_space_chat_channel_members (channel_id)');
        $this->addSql('CREATE INDEX IDX_B3E5D19672F5A1AA ON core_studio_space_chat_channel_members (channel_id)');
        $this->addSql('CREATE INDEX IDX_B3E5D196A76ED395 ON core_studio_space_chat_channel_members (user_id)');
        $this->addSql('CREATE INDEX IDX_B3E5D196ADA40271 ON core_studio_space_chat_channel_members (link_id)');
        $this->addSql('ALTER TABLE core_studio_space_chat_channel_members ADD CONSTRAINT FK_B3E5D19672F5A1AA FOREIGN KEY (channel_id) REFERENCES core_studio_space_chat_channels (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_studio_space_chat_channel_members ADD CONSTRAINT FK_B3E5D196A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_studio_space_chat_channel_members ADD CONSTRAINT FK_B3E5D196ADA40271 FOREIGN KEY (link_id) REFERENCES core_studio_space_access_links (id) ON DELETE SET NULL NOT DEFERRABLE');

        // 1. The column, nullable while it is filled.
        $this->addSql('ALTER TABLE core_studio_space_chat_messages ADD channel_id INT DEFAULT NULL');

        // 2. One main channel per space, open to the client as the
        //    conversation it replaces was. The name is in French: that is the
        //    language of the application, and it can be renamed.
        $this->addSql(<<<'SQL'
            INSERT INTO core_studio_space_chat_channels (id, space_id, name, kind, position, open_to_client, created_at)
            SELECT nextval('seq_core_space_chat_channel_id'), s.id, 'Général', 'main', 0, true, NOW()
            FROM core_studio_customer_spaces s
            SQL);

        // 3. Messages join the main channel of their space.
        $this->addSql(<<<'SQL'
            UPDATE core_studio_space_chat_messages m
            SET channel_id = c.id
            FROM core_studio_space_chat_channels c
            WHERE c.space_id = m.space_id AND c.kind = 'main'
            SQL);

        $this->addSql('ALTER TABLE core_studio_space_chat_messages ALTER COLUMN channel_id SET NOT NULL');
        $this->addSql('CREATE INDEX IDX_8D9F2C1172F5A1AA ON core_studio_space_chat_messages (channel_id)');
        $this->addSql('CREATE INDEX idx_space_chat_channel_created ON core_studio_space_chat_messages (channel_id, created_at)');
        $this->addSql('ALTER TABLE core_studio_space_chat_messages ADD CONSTRAINT FK_8D9F2C1172F5A1AA FOREIGN KEY (channel_id) REFERENCES core_studio_space_chat_channels (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_chat_messages DROP CONSTRAINT FK_8D9F2C1172F5A1AA');
        $this->addSql('DROP INDEX idx_space_chat_channel_created');
        $this->addSql('DROP INDEX IDX_8D9F2C1172F5A1AA');

        // Messages of the channels other than the main one would go with the
        // channels: they are moved into the original conversation, which is
        // what this database could represent before.
        $this->addSql('ALTER TABLE core_studio_space_chat_messages DROP COLUMN channel_id');

        $this->addSql('ALTER TABLE core_studio_space_chat_channel_members DROP CONSTRAINT FK_B3E5D196ADA40271');
        $this->addSql('ALTER TABLE core_studio_space_chat_channel_members DROP CONSTRAINT FK_B3E5D196A76ED395');
        $this->addSql('ALTER TABLE core_studio_space_chat_channel_members DROP CONSTRAINT FK_B3E5D19672F5A1AA');
        $this->addSql('DROP TABLE core_studio_space_chat_channel_members');

        $this->addSql('ALTER TABLE core_studio_space_chat_channels DROP CONSTRAINT FK_C1A7F4D123575340');
        $this->addSql('DROP TABLE core_studio_space_chat_channels');

        $this->addSql('DROP SEQUENCE seq_core_space_chat_channel_member_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_space_chat_channel_id CASCADE');
    }
}
