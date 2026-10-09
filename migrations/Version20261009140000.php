<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A note gets an emoji, properties, a lock and reading settings (09/10/2026).
 *
 * **Nothing changes for an existing note**: no icon, no property, unlocked,
 * in a column, normal size, the interface's typeface - what every note
 * looked like until now.
 */
final class Version20261009140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: icon, properties, lock and reading settings on a note';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD icon VARCHAR(32) DEFAULT NULL');
        $this->addSql("ALTER TABLE core_notes_markdown_notes ADD properties JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD locked BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD full_width BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD small_text BOOLEAN DEFAULT false NOT NULL');
        $this->addSql("ALTER TABLE core_notes_markdown_notes ADD font VARCHAR(8) DEFAULT 'sans' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP icon');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP properties');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP locked');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP full_width');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP small_text');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP font');
    }
}
