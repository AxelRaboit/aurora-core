<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A board stage can be none of the client's business.
 *
 * True everywhere at first, which is the state of every existing column: so
 * far the client saw everything. Closing them regardless would have emptied
 * the spaces in progress on the day of the update.
 */
final class Version20260920100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A board column can be kept from the client';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_content_columns ADD visible_to_client BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_content_columns DROP visible_to_client');
    }
}
