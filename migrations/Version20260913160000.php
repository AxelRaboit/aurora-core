<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A deck can be a model that other decks are opened from.
 *
 * A flag rather than a table, because a template *is* a deck: it is composed,
 * previewed, presented and shared like any other, and the day somebody wants to
 * show one they should not have to convert it first. What the column buys is a
 * filter in the list and a place in the picker that opens a new deck.
 *
 * Default false, so nothing already written becomes a model by surprise.
 */
final class Version20260913160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A deck can be marked as a model';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_decks ADD template BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('CREATE INDEX idx_decks_template ON core_decks (template)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_decks_template');
        $this->addSql('ALTER TABLE core_decks DROP template');
    }
}
