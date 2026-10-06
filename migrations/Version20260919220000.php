<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A card can carry a date without appearing in the calendar.
 *
 * True everywhere at first, which is the state of every existing card: until
 * now, a date meant a publication. The default stays true so that nobody has
 * to tick anything to get back what they had.
 */
final class Version20260919220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A content item can carry a date without showing on the calendar';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_content_items ADD show_on_calendar BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_content_items DROP show_on_calendar');
    }
}
