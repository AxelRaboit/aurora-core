<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A deck carries its own look.
 *
 * Every deck was drawn in the back office's own grey, because the frame that
 * draws a slide wrote its colours as constants and nothing in the model could
 * say otherwise. `theme` names one of the looks declared in `DeckThemeEnum`,
 * and `style` holds whatever this deck changes about it: a colour, a pair of
 * faces, a logo, a footer line.
 *
 * Both defaults are the existing behaviour, spelled out. `slate` is the look
 * every deck already had, and an empty style overrides nothing, so no deck
 * composed before today changes appearance when this runs.
 */
final class Version20260913140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A deck carries a theme and its own style overrides';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE core_decks ADD theme VARCHAR(20) DEFAULT 'slate' NOT NULL");
        $this->addSql("ALTER TABLE core_decks ADD style JSON DEFAULT '{}' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_decks DROP style');
        $this->addSql('ALTER TABLE core_decks DROP theme');
    }
}
