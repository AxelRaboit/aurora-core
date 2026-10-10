<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A client space link may show the customer's contracts (10/10/2026).
 *
 * False for every existing link: contracts are shown by choice, like
 * everything that reaches the client.
 */
final class Version20261010230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Studio: contracts shown in the client space, per link';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_access_links ADD can_see_contracts BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_access_links DROP can_see_contracts');
    }
}
