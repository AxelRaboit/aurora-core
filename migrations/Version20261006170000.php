<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The Craft import moved from Studio to Notes, and its three settings follow.
 *
 * `suite_studio_craft_enabled`, `_endpoint` and `_token` become
 * `suite_notes_craft_*`. **The values are kept as they are**: the token is
 * stored encrypted with the application key, which does not change, so the
 * connection an installation had opened stays open without anybody pasting
 * the key again. Only the key column is rewritten.
 *
 * A row already present under the new name wins: it can only have been written
 * by the new code, after the old one stopped reading the old row.
 */
final class Version20261006170000 extends AbstractMigration
{
    private const array SUFFIXES = ['enabled', 'endpoint', 'token'];

    public function getDescription(): string
    {
        return 'Craft settings: suite_studio_craft_* become suite_notes_craft_*';
    }

    public function up(Schema $schema): void
    {
        $this->rename('suite_studio_craft_', 'suite_notes_craft_');
    }

    public function down(Schema $schema): void
    {
        $this->rename('suite_notes_craft_', 'suite_studio_craft_');
    }

    private function rename(string $from, string $to): void
    {
        foreach (self::SUFFIXES as $suffix) {
            $this->addSql(
                'UPDATE core_settings SET setting_key = :to WHERE setting_key = :from AND NOT EXISTS (SELECT 1 FROM core_settings existing WHERE existing.setting_key = :to)',
                ['from' => $from.$suffix, 'to' => $to.$suffix],
            );
            $this->addSql('DELETE FROM core_settings WHERE setting_key = :from', ['from' => $from.$suffix]);
        }
    }
}
