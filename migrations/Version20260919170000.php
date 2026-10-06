<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A space can name the Drive folder its client shared.
 *
 * Null everywhere at first, which is the state of every existing space: none
 * has a Drive, and most never will. The id rather than the address - that is
 * what Google expects, and it is what follows `/folders/`.
 */
final class Version20260919170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A space can name the Drive folder its client shared';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_customer_spaces ADD drive_folder_id VARCHAR(128) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_customer_spaces DROP COLUMN drive_folder_id');
    }
}
