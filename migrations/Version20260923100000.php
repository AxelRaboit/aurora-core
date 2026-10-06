<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A note can carry a cover image and an appearance.
 *
 * The image stays with whoever hosts it: only its address, the
 * photographer's name and the link to their page are kept, because the
 * licence requires credit and, with the image outside the media library,
 * this is the only place left to give it. Nothing enters the GED, on purpose.
 *
 * `appearance` defaults to `plain`: no existing note changes its look on
 * the day of the migration.
 */
final class Version20260923100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: a cover image and an appearance on a note';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD cover_url VARCHAR(1024) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD cover_credit_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD cover_credit_url VARCHAR(1024) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD cover_position INT DEFAULT 50 NOT NULL');
        $this->addSql("ALTER TABLE core_notes_markdown_notes ADD appearance VARCHAR(20) DEFAULT 'plain' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP cover_url');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP cover_credit_name');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP cover_credit_url');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP cover_position');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP appearance');
    }
}
