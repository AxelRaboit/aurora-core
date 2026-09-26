<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add share_links to posts: the links of a publication's share block, in
 * order. Nullable - null means never configured, and the page keeps the
 * default row it has always shown.
 */
final class Version20260926200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add share_links to core_posts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts ADD share_links JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts DROP share_links');
    }
}
