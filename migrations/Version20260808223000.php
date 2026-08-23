<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Moves "is the sidemenu folded" from the browser onto the user.
 *
 * It was the odd one out: hiding a section or recolouring one followed the
 * account, folding the whole menu followed the machine. Storing it here also
 * lets the layout render the collapsed class itself, so the menu stops
 * starting expanded and snapping shut once a script has run.
 *
 * Nothing is carried over. The old value lived in each visitor's localStorage
 * and no server ever saw it, so everyone starts expanded and folds it once
 * more - the alternative being a piece of migration code that reads a browser
 * key, which does not exist.
 *
 * The width stays where it was: a number of pixels describes the screen it was
 * dragged on, and carrying 420 from a 27-inch monitor to a laptop is worse
 * than forgetting it.
 *
 * Written by hand rather than generated, as with the banner ones:
 * `migrations:diff` still wants to drop every table belonging to the extracted
 * modules, which buries the one statement that matters.
 */
final class Version20260808223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the collapsed sidemenu on the user rather than in the browser';
    }

    public function up(Schema $schema): void
    {
        // DEFAULT is required, not cosmetic: the table already has rows, and
        // PostgreSQL refuses to add a NOT NULL column to them without one.
        $this->addSql('ALTER TABLE core_users ADD sidemenu_collapsed BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_users DROP sidemenu_collapsed');
    }
}
