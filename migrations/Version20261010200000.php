<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The language a customer is written to in (10/10/2026).
 *
 * Nullable, and null for every existing customer: null means « the site's
 * email language », which is what they were all written in until now.
 */
final class Version20261010200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Studio: a language on the customer sheet';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_customers ADD locale VARCHAR(5) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_customers DROP locale');
    }
}
