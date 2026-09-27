<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Useful links become the site's by default.
 *
 * `useful_links` turns nullable: null now means "follow the site's list",
 * set once in Configuration. The pages that never had links of their own
 * (an empty list, the old default) are moved to null, so they pick the
 * site's list up; a page that did choose links keeps them.
 *
 * `useful_links_enabled` turns on by default and for every page: the site's
 * list is meant for all of them, and while it is empty nothing shows.
 */
final class Version20260927230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Useful links follow the site list by default and are on for every post';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts ALTER useful_links DROP NOT NULL');
        $this->addSql('ALTER TABLE core_posts ALTER useful_links DROP DEFAULT');
        $this->addSql("UPDATE core_posts SET useful_links = NULL WHERE useful_links::text = '[]'");
        $this->addSql('ALTER TABLE core_posts ALTER useful_links_enabled SET DEFAULT true');
        $this->addSql('UPDATE core_posts SET useful_links_enabled = true');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE core_posts SET useful_links = '[]' WHERE useful_links IS NULL");
        $this->addSql("ALTER TABLE core_posts ALTER useful_links SET DEFAULT '[]'");
        $this->addSql('ALTER TABLE core_posts ALTER useful_links SET NOT NULL');
        $this->addSql('ALTER TABLE core_posts ALTER useful_links_enabled SET DEFAULT false');
    }
}
