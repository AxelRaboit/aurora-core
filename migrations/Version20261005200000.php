<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La corbeille des présentations : une date de mise à la corbeille, nulle tant
 * que la présentation est vivante. Les présentations existantes le restent.
 */
final class Version20261005200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Decks: a trash date, null while alive';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_decks ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_deck_deleted_at ON core_decks (deleted_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_deck_deleted_at');
        $this->addSql('ALTER TABLE core_decks DROP deleted_at');
    }
}
