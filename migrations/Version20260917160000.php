<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A note is either shared with the team or personal.
 *
 * Written by hand, like the ones before it: `migrations:diff` on this
 * development database also proposes renaming two dozen indexes it did not
 * create.
 *
 * Existing notes become shared, and that is the right reading: they were
 * taken when the only way to take one was on the common wall.
 *
 * No index: the filter never applies alone, always under the space, and the
 * composite index that already serves the read brings back few enough rows
 * for the rest to be read in memory.
 */
final class Version20260917160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shared or personal notes on a client space';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE core_studio_space_notes ADD visibility VARCHAR(20) DEFAULT 'shared' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_notes DROP visibility');
    }
}
