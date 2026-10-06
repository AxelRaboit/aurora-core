<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The appearance of a post joins that of the theme.
 *
 * `color_overrides` holds the theme colors that a post repaints on top of the
 * background, the topbar and the footer: text, rules, cards, headings,
 * figures. Empty by default, so every post keeps the rendering it had.
 * `chrome_follows_page` says whether the topbar and the footer take the
 * page's accent and hovers; false by default, for the same reason.
 */
final class Version20261003130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Posts: more theme colours per post, and topbar and footer following the page';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE core_posts ADD color_overrides JSON DEFAULT '{}' NOT NULL");
        $this->addSql('ALTER TABLE core_posts ADD chrome_follows_page BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts DROP color_overrides');
        $this->addSql('ALTER TABLE core_posts DROP chrome_follows_page');
    }
}
