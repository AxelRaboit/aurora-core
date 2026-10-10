<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the flag behind the menu's "show descriptions" switch.
 *
 * The switch is gone (visual redesign of the suite, 10/10/2026): every menu
 * item shows its description under its label, for everybody. A preference
 * nobody can reach any more is a column that only goes stale.
 *
 * `down()` puts the column back with its old default, so a rollback gives
 * every account the descriptions, which is what they see now anyway.
 *
 * Written by hand, as with every migration here (see Version20260823040000).
 */
final class Version20261010120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop core_users.sidemenu_show_descriptions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_users DROP sidemenu_show_descriptions');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_users ADD sidemenu_show_descriptions BOOLEAN DEFAULT true NOT NULL');
    }
}
