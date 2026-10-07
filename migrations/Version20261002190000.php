<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The "Format des dates" setting becomes a style: short, medium, long.
 *
 * It held a PHP pattern (`d/m/Y` by default) that nothing read, and that
 * could not have served three languages. Dates in emails, PDFs and contract
 * pages now follow the document's language, in the chosen style; `d/m/Y`
 * described the short one, so that is what it becomes. An unknown pattern
 * also reads as the short one, whether the migration ran or not.
 */
final class Version20261002190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Settings: the date format becomes a style (short, medium, long)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE core_settings SET value = 'short', setting_type = 'select' WHERE setting_key = 'date_format' AND (value IS NULL OR value NOT IN ('short', 'medium', 'long'))");
        $this->addSql("UPDATE core_settings SET setting_type = 'select' WHERE setting_key = 'date_format'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE core_settings SET value = 'd/m/Y', setting_type = 'string' WHERE setting_key = 'date_format'");
    }
}
