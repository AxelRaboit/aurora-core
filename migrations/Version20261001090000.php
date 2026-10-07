<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A contract can carry a text adapted for its client.
 *
 * The text of a part (body, appendix) changed for this contract alone, without
 * creating a template: it is sealed with the contract and goes away with it.
 * Empty for every existing contract, which keeps the text of its template.
 */
final class Version20261001090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contracts: wording adapted for one contract';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE core_contracts ADD adapted_wording JSON DEFAULT '{}' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_contracts DROP adapted_wording');
    }
}
