<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A contract template says which trade it is for.
 *
 * The library held one kind of engagement, so an alphabetical list of bodies
 * and annexes was enough to find anything. A business that sells two things
 * loses that immediately: the list mixes trades, and the picker offers a
 * wording that has nothing to do with the contract being drafted.
 *
 * Nullable, and left null here. Guessing would mean this migration deciding,
 * for every installation that runs it, which trade somebody else's contracts
 * belong to - and being wrong on a document that ends up signed.
 */
final class Version20260913150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Category on contract templates';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_contract_templates ADD category VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_contract_templates_category ON core_contract_templates (category)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_contract_templates_category');
        $this->addSql('ALTER TABLE core_contract_templates DROP category');
    }
}
