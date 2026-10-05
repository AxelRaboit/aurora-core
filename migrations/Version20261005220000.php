<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hide a retired link from its list: a date, null while the link is shown.
 * Deliverable reading links and presentation share links, the same rule.
 */
final class Version20261005220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reading and share links: a hidden date, null while shown';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_deliverable_links ADD hidden_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE core_deck_share_links ADD hidden_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_deliverable_links DROP hidden_at');
        $this->addSql('ALTER TABLE core_deck_share_links DROP hidden_at');
    }
}
