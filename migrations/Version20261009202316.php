<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The short address of a client space's link (10/10/2026): its name,
 * encrypted, and the hash it is found by, unique.
 */
final class Version20261009202316 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Studio: short address of a client space link';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_access_links ADD alias TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE core_studio_space_access_links ADD alias_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AAE387E142941398 ON core_studio_space_access_links (alias_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_AAE387E142941398');
        $this->addSql('ALTER TABLE core_studio_space_access_links DROP alias');
        $this->addSql('ALTER TABLE core_studio_space_access_links DROP alias_hash');
    }
}
