<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add useful_links_enabled and useful_links to posts: the block of links a
 * publication may end with (where else to find the author, the product, the
 * code). Off and empty by default, so no page changes on deploy.
 */
final class Version20260927210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add useful_links_enabled and useful_links to core_posts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts ADD useful_links_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql("ALTER TABLE core_posts ADD useful_links JSON DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts DROP useful_links_enabled');
        $this->addSql('ALTER TABLE core_posts DROP useful_links');
    }
}
