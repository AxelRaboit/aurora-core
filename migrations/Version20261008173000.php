<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A space can allow its notes to be written by several people at once.
 *
 * **Off everywhere, and that is the whole upgrade.** A co-editing session
 * hands a note's text to the other browsers while it is open, so it is a thing
 * a space says about itself rather than a default: nobody gets it without
 * asking, and no existing space changes behaviour.
 *
 * **A personal space is never eligible**, and that is not enforced here. The
 * column can hold true on one and `allowsCoediting()` still answers false: a
 * private notebook does not change the promise it made, and putting the rule
 * in the entity rather than in a constraint means no screen and no route has
 * to remember it.
 *
 * Arrives with the session that uses it, never before - an interrupt that
 * announces a capability nothing serves is worse here than elsewhere, because
 * a space ticked "co-editable" with nothing behind it would be announcing an
 * exposure that does not exist.
 */
final class Version20261008173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: a space can allow co-editing, off everywhere and never on a personal one';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_spaces ADD coediting BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_spaces DROP coediting');
    }
}
