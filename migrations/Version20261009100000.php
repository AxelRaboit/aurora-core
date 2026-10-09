<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A writing link can open its note to live co-editing.
 *
 * **Off everywhere, and that is the whole upgrade.** Ticked, whoever holds the
 * address writes the note with everybody else on it, letter by letter, the way
 * a shared document does - even a note of a personal space. Unticked, the link
 * writes the way it always has: type, then save, refused if the note moved
 * meanwhile. No existing link changes behaviour, and nobody gets the live
 * session without asking for it on the link (decided with Axel, 09/10/2026).
 *
 * **Only ever on a link that writes**, and that is not enforced here: the
 * entity answers false on a reading link whatever the column holds, so no
 * screen and no route has to remember it.
 */
final class Version20261009100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: a writing share link can open live co-editing, off everywhere';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_share_links ADD coediting BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_share_links DROP coediting');
    }
}
