<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A board's step can wear one of the palette's colours.
 *
 * Nullable, and null is a real answer rather than a missing one: a board where
 * every step is coloured is a board where colour has stopped meaning anything.
 * The boards already created keep none, which is the honest default - nobody
 * chose a colour for them, so the software should not either.
 */
final class Version20260915220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A board step carries a palette slot';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_content_columns ADD colour_slot INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_content_columns DROP colour_slot');
    }
}
