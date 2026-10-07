<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The Drive tab of a space can be closed with a password.
 *
 * Hashed and not encrypted: a password never needs to be read back, only
 * compared. Null everywhere at first, that is, open, which is the state of
 * every existing space.
 */
final class Version20260920080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A space can close its Drive tab behind a password';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_customer_spaces ADD drive_password VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_customer_spaces DROP drive_password');
    }
}
