<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The back-office is now « la suite »: `/suite` in the address, `suite_*`
 * route names, `suite.*` translation keys, `suite` setting keys and user type.
 *
 * What the database holds under the old word:
 * - setting keys (`backend_email`, `modules_studio_backend`...) and their
 *   descriptions, which are translation keys (`backend.parameters...`);
 * - route names inside the JSON of the four navigation settings and of each
 *   user's hidden entries, so a renamed or hidden menu entry stays so;
 * - each user's switched-off modules (`modules_x_backend`);
 * - the user type `backend`, and its column default.
 *
 * Secrets keep working: they are sealed with libsodium's secretbox, which does
 * not bind the ciphertext to its setting key. Every statement only touches rows
 * that still hold the old word, so a second run changes nothing; `down()` is
 * the mirror.
 */
final class Version20261005230000 extends AbstractMigration
{
    private const string NAV_SETTINGS = "'nav_section_aliases', 'nav_item_aliases', 'nav_section_order', 'nav_item_order'";

    public function getDescription(): string
    {
        return 'Rename the back-office to « suite » in stored settings, menu preferences and user types';
    }

    public function up(Schema $schema): void
    {
        $this->rename('backend', 'suite');
    }

    public function down(Schema $schema): void
    {
        $this->rename('suite', 'backend');
    }

    private function rename(string $from, string $to): void
    {
        $this->addSql(sprintf(
            "UPDATE core_settings SET setting_key = REPLACE(setting_key, '%s', '%s') WHERE setting_key LIKE '%%%s%%'",
            $from,
            $to,
            $from,
        ));
        $this->addSql(sprintf(
            "UPDATE core_settings SET description = REPLACE(description, '%s', '%s') WHERE description LIKE '%%%s%%'",
            $from,
            $to,
            $from,
        ));
        $this->addSql(sprintf(
            "UPDATE core_settings SET \"value\" = REPLACE(\"value\", '%s_', '%s_') WHERE setting_key IN (%s) AND \"value\" LIKE '%%%s\\_%%'",
            $from,
            $to,
            self::NAV_SETTINGS,
            $from,
        ));

        $this->addSql(sprintf(
            "UPDATE core_users SET hidden_nav_items = REPLACE(hidden_nav_items::text, '%s_', '%s_')::json WHERE hidden_nav_items::text LIKE '%%%s\\_%%'",
            $from,
            $to,
            $from,
        ));
        $this->addSql(sprintf(
            "UPDATE core_users SET disabled_modules = REPLACE(disabled_modules::text, '_%s\"', '_%s\"')::json WHERE disabled_modules::text LIKE '%%\\_%s\"%%'",
            $from,
            $to,
            $from,
        ));
        $this->addSql(sprintf("UPDATE core_users SET type = '%s' WHERE type = '%s'", $to, $from));
        $this->addSql(sprintf("ALTER TABLE core_users ALTER type SET DEFAULT '%s'", $to));
    }
}
