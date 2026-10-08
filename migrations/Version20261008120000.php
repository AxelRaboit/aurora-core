<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A share link can be opened for writing, and the history says who used it.
 *
 * **`can_write` arrives with its route, not before.** It was deliberately
 * absent until now: a column announcing "this link may write" while no write
 * endpoint existed would have been a switch somebody flips, nothing happens,
 * and they go looking for the bug elsewhere. The endpoint
 * (`notes_share_save`) and its rate limit (`notes_share_write`) land in the
 * same change as this column.
 *
 * **`via_link_id` is the guest's identity.** A write that arrives through a
 * link has no account behind it - the address *was* the identity - so the
 * revision points at the link rather than at a person. Pointing rather than
 * copying its label keeps the recipient's address in one table, and it
 * survives revocation, which is exactly when somebody asks who wrote this.
 * `SET NULL`: deleting a link must not take the versions written through it.
 *
 * Both default to the previous behaviour: every existing link stays read-only,
 * and every existing revision keeps naming its account.
 */
final class Version20261008120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: a share link that may write its own note, and revisions that name the link used';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_share_links ADD can_write BOOLEAN DEFAULT false NOT NULL');

        $this->addSql('ALTER TABLE core_notes_markdown_revisions ADD via_link_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_44868C83A734AF64 ON core_notes_markdown_revisions (via_link_id)');
        $this->addSql('ALTER TABLE core_notes_markdown_revisions ADD CONSTRAINT FK_44868C83A734AF64 FOREIGN KEY (via_link_id) REFERENCES core_notes_markdown_share_links (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_revisions DROP CONSTRAINT FK_44868C83A734AF64');
        $this->addSql('DROP INDEX IDX_44868C83A734AF64');
        $this->addSql('ALTER TABLE core_notes_markdown_revisions DROP via_link_id');
        $this->addSql('ALTER TABLE core_notes_markdown_share_links DROP can_write');
    }
}
