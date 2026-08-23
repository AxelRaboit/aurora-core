<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets a calendar publish an iCalendar feed.
 *
 * A feed is fetched by a phone's calendar app with no session, so the URL is the
 * credential - the same model as Google's "secret address in iCal format". The
 * column is nullable because publishing is opt-in: no calendar leaks by default,
 * and null is the state of every calendar that exists today.
 *
 * Unique, so a token identifies exactly one calendar and the lookup is an index
 * hit rather than a scan of every row for every fetch - and a phone polls this
 * every fifteen minutes, for ever.
 *
 * Written by hand, as every migration here has to be.
 */
final class Version20260823230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add feed_token to core_plannings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_plannings ADD feed_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_planning_feed_token ON core_plannings (feed_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_planning_feed_token');
        $this->addSql('ALTER TABLE core_plannings DROP feed_token');
    }
}
