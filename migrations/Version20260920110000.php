<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An access link may or may not show the Drive folder.
 *
 * True everywhere at first: that is how the links already out there behave,
 * and a new right defaulting to false would take the folder away from them
 * without anyone asking for it.
 */
final class Version20260920110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An access link can be kept from the Drive folder';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_access_links ADD can_see_drive BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_access_links DROP can_see_drive');
    }
}
