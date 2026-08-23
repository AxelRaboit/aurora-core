<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets a module own a calendar.
 *
 * One nullable column and one unique index, which is the whole feature: a module
 * announcing that its entities have dates needs somewhere to put them, and it has
 * to be the same somewhere every time or every announcement makes another
 * calendar.
 *
 * Nullable rather than a third visibility case. Who owns a calendar and who may
 * see it are two questions, and folding them into one column would mean a module
 * calendar could not also be private - which is a decision nobody has made yet.
 *
 * Written by hand, as every migration here has to be.
 */
final class Version20260823180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add source_type to core_plannings and source_url to core_planning_events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_plannings ADD source_type VARCHAR(64) DEFAULT NULL');
        // Unique over a nullable column: Postgres treats NULLs as distinct, so
        // this constrains module calendars to one each and leaves every
        // person-made calendar alone.
        $this->addSql('CREATE UNIQUE INDEX uniq_planning_source ON core_plannings (source_type)');

        // The other half of a synced event: where the thing itself lives. It
        // cannot be edited from the calendar, so going to the source is the only
        // useful gesture, and without a column the screen could only name it.
        $this->addSql('ALTER TABLE core_planning_events ADD source_url VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_planning_events DROP source_url');
        $this->addSql('DROP INDEX uniq_planning_source');
        $this->addSql('ALTER TABLE core_plannings DROP source_type');
    }
}
