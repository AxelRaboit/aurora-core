<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prospect or client, on the customer.
 *
 * Written by hand, like the ones before it: `migrations:diff` on this
 * development database also proposes renaming two dozen indexes it did not
 * create.
 *
 * **The column defaults to prospect and every existing row is set to client**,
 * and the two are not in conflict. A record created from now on is a prospect,
 * because that is what a company is at the moment somebody opens a space for
 * it; the records that already exist are customers who were entered the only
 * way there was - through the customers screen, to be contracted - and calling
 * them prospects tomorrow morning would be telling the reader something false
 * about their own file.
 *
 * Nothing else changes. A prospect is a customer whose legal identity is not
 * filled in yet, and every one of those columns - SIRET, legal form,
 * registered office, representative - was already nullable. The contractual
 * email stays required: it is where an access link is sent, so a company you
 * have opened a space for has one by definition.
 */
final class Version20260917110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prospect or client, on the customer';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE core_customers ADD status VARCHAR(20) DEFAULT 'prospect' NOT NULL");

        // Everything that predates this column was entered as a customer.
        $this->addSql("UPDATE core_customers SET status = 'client'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_customers DROP status');
    }
}
