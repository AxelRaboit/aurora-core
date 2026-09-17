<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A prospect may be nothing but a name.
 *
 * The column was required on the reasoning that a company you work with is a
 * company you can write to. That is true of a client and not of a prospect:
 * you meet somebody, you open a space to start structuring the work, and a
 * name is all you have. A space's access links carry their own recipient, so
 * nothing on that screen ever needed this.
 *
 * Still required of a client, and enforced by the Manager rather than by the
 * column: the rule is about the pair - the status and the address - and a NOT
 * NULL cannot read the status.
 *
 * `down()` puts the constraint back and would fail on any prospect created in
 * the meantime, which is the honest behaviour: the rows exist and the old
 * schema has nowhere to put them.
 */
final class Version20260917120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A prospect may be nothing but a name';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_customers ALTER contractual_email DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_customers ALTER contractual_email SET NOT NULL');
    }
}
