<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The generation of the sessions open on a space's Drive.
 *
 * Null everywhere at first, which closes the current sessions: they hold a
 * generation that no longer matches anything. That is the intended effect,
 * and it only costs typing the password again.
 */
final class Version20260920130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A space carries the generation of its open Drive sessions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_customer_spaces ADD drive_lock_generation VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_customer_spaces DROP drive_lock_generation');
    }
}
