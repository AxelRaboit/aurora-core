<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A publication can be read through a link only.
 *
 * "site" for every existing publication, which stays on the site exactly as
 * before. "link" takes a publication out of the site: no more address under
 * the site, no more list, menu, sitemap or search.
 */
final class Version20261001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Posts: visibility (on the site, or by link only)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE core_posts ADD visibility VARCHAR(20) DEFAULT 'site' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts DROP visibility');
    }
}
