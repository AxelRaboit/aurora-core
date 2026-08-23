<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the flag behind the menu's "show descriptions" switch.
 *
 * Beside `sidemenu_collapsed` and the hidden-section columns rather than in the
 * browser, for the reason those give: hiding a section was remembered per
 * account and folding the menu per machine, which made one preference two
 * things.
 *
 * Defaults to true, which the `ADD COLUMN` also writes onto every existing
 * row - so accounts that predate the switch get the descriptions too, rather
 * than a feature only new users ever see.
 *
 * Written by hand, as with every migration here: `doctrine:migrations:diff`
 * compares the dev database against the entities currently mapped, and every
 * module absent from this checkout reads as a table to drop. Asked for it out of
 * habit before writing this and it produced 692 statements, most of them
 * dropping sequences - which `make aurora-update` would have run unattended on
 * a consumer, since it calls `migrate-f`.
 */
final class Version20260823040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add core_users.sidemenu_show_descriptions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_users ADD sidemenu_show_descriptions BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_users DROP sidemenu_show_descriptions');
    }
}
