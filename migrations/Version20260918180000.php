<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Des canaux dans la discussion d'un espace, et les gens qui y sont.
 *
 * Écrite à la main comme celles qui précèdent : `migrations:diff` sur cette
 * base de développement propose aussi de renommer deux douzaines d'index qu'il
 * n'a pas créés.
 *
 * **Le rattachement des messages se fait en trois temps, et c'est obligatoire.**
 * La colonne arrive nullable, chaque espace reçoit son canal principal, tous
 * ses messages y sont versés, et la colonne devient non nulle une fois qu'il
 * n'en reste aucun sans canal. L'ordre inverse refuserait la colonne sur toute
 * base qui contient déjà une conversation, c'est-à-dire sur la production.
 *
 * Le canal principal est créé pour **tout** espace, même muet : la discussion
 * s'ouvre sur un canal, et un espace sans canal obligerait chaque écran à
 * savoir en fabriquer un.
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

        // 1. La colonne, nullable le temps du remplissage.
        $this->addSql('ALTER TABLE core_studio_space_chat_messages ADD channel_id INT DEFAULT NULL');

        // 2. Un canal principal par espace, ouvert au client comme l'était la
        //    discussion qu'il remplace. Le nom est en français : c'est la
        //    langue de l'application, et il se renomme.
        $this->addSql(<<<'SQL'
            INSERT INTO core_studio_space_chat_channels (id, space_id, name, kind, position, open_to_client, created_at)
            SELECT nextval('seq_core_space_chat_channel_id'), s.id, 'Général', 'main', 0, true, NOW()
            FROM core_studio_customer_spaces s
            SQL);

        // 3. Les messages rejoignent le canal principal de leur espace.
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

        // Les messages des canaux qui ne sont pas le principal partiraient avec
        // les canaux : ils sont versés dans la discussion d'origine, qui est ce
        // que cette base savait représenter avant.
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
