<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A conversation on the space itself, beside the ones on its cards.
 *
 * Written by hand, like the ones before it: `migrations:diff` on this
 * development database also proposes renaming two dozen indexes it did not
 * create, so its output is read for the identifiers and not run.
 *
 * **Nothing is migrated into it, and that is the honest shape of the change.**
 * The card threads are not moved here and are not going to be: a message about
 * one post belongs on that post, where somebody reopening it a month later
 * finds the objection next to what was objected to. What this table holds is
 * what had nowhere to go - the brief for next month, a campaign moving, a
 * question that is not about any one card - and none of that exists yet,
 * because until now there was nowhere to put it.
 *
 * The composite index is the one the screens actually read on: every query is
 * one space's messages in date order. The single-column index beside it is
 * Doctrine's, for the foreign key.
 *
 * The two author columns are `SET NULL` for the reason the card threads give:
 * deleting an account or revoking an address must not delete what the person
 * said. The durable name travels in `author_label`, written once.
 */
final class Version20260916230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "A space's own conversation";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_space_chat_message_id INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql('CREATE TABLE core_studio_space_chat_messages (id INT NOT NULL, space_id INT NOT NULL, author_user_id INT DEFAULT NULL, author_link_id INT DEFAULT NULL, body TEXT NOT NULL, author_label VARCHAR(180) NOT NULL, from_client BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');

        $this->addSql('CREATE INDEX idx_space_chat_space_created ON core_studio_space_chat_messages (space_id, created_at)');
        $this->addSql('CREATE INDEX IDX_D9B1770A23575340 ON core_studio_space_chat_messages (space_id)');
        $this->addSql('CREATE INDEX IDX_D9B1770AE2544CD6 ON core_studio_space_chat_messages (author_user_id)');
        $this->addSql('CREATE INDEX IDX_D9B1770AE89E9D32 ON core_studio_space_chat_messages (author_link_id)');

        $this->addSql('ALTER TABLE core_studio_space_chat_messages ADD CONSTRAINT FK_D9B1770A23575340 FOREIGN KEY (space_id) REFERENCES core_studio_customer_spaces (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_studio_space_chat_messages ADD CONSTRAINT FK_D9B1770AE2544CD6 FOREIGN KEY (author_user_id) REFERENCES core_users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_studio_space_chat_messages ADD CONSTRAINT FK_D9B1770AE89E9D32 FOREIGN KEY (author_link_id) REFERENCES core_studio_space_access_links (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_chat_messages DROP CONSTRAINT FK_D9B1770AE89E9D32');
        $this->addSql('ALTER TABLE core_studio_space_chat_messages DROP CONSTRAINT FK_D9B1770AE2544CD6');
        $this->addSql('ALTER TABLE core_studio_space_chat_messages DROP CONSTRAINT FK_D9B1770A23575340');
        $this->addSql('DROP TABLE core_studio_space_chat_messages');
        $this->addSql('DROP SEQUENCE seq_core_space_chat_message_id CASCADE');
    }
}
