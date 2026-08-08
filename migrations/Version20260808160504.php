<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the per-translation banner configuration.
 *
 * Written by hand rather than kept as generated. `migrations:diff` produced
 * 688 statements here, because this database still holds the tables of the
 * modules that were extracted (Agency, Invoice, Cart, Listing, Employee and
 * the rest) while the mapping no longer declares them — so the diff wanted to
 * drop every one of them. Two of those statements were the banner. Dropping
 * the extracted tables may well be worth doing, but it is its own decision and
 * its own migration, not a side effect of adding a column.
 */
final class Version20260808160504 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the banner JSON column to post translations';
    }

    public function up(Schema $schema): void
    {
        // DEFAULT is required, not cosmetic: the table already has rows, and
        // PostgreSQL refuses to add a NOT NULL column to them without one.
        $this->addSql("ALTER TABLE core_post_translations ADD banner JSON DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_post_translations DROP banner');
    }
}
