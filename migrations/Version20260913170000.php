<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A share link can count its openings and ask for a password.
 *
 * `open_count` answers the question a sender actually has - did they read it,
 * and did they come back to it - with a number rather than with a log. A row
 * per opening would be a record of somebody's reading habits kept because it
 * was easy to keep.
 *
 * `password_hash` is hashed where the token is not, and the difference is the
 * point: the address is the credential and hashing it would buy nothing, since
 * a database of decks that leaks has already leaked the decks. People reuse
 * passwords, so what leaks here must not open anything else.
 */
final class Version20260913170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A deck share link counts its openings and may carry a password';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_deck_share_links ADD open_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE core_deck_share_links ADD password_hash VARCHAR(255) DEFAULT NULL');

        // A link opened before today has been opened at least once, and zero
        // would read as "never opened" beside a date that says otherwise.
        $this->addSql('UPDATE core_deck_share_links SET open_count = 1 WHERE last_used_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_deck_share_links DROP password_hash');
        $this->addSql('ALTER TABLE core_deck_share_links DROP open_count');
    }
}
