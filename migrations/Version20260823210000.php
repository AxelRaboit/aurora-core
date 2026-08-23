<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets an alert be pinned to a moment instead of an offset.
 *
 * Google and Apple both offer a menu of offsets plus a custom option, and Apple's
 * custom option is an absolute moment. That is a different thing from an offset,
 * not a wider range of one: a relative alert follows its event, so moving a
 * meeting moves its "30 minutes before" with it, while an alert set for Tuesday
 * at 09:00 has to stay on Tuesday at 09:00. The reader asked for that moment, not
 * for a distance.
 *
 * So `minutes_before` becomes nullable, and null is what marks the second kind.
 *
 * The unique constraint moves with it. `(event_id, minutes_before)` was the right
 * rule while every alert was an offset; with nulls it stops constraining the
 * custom ones at all, because Postgres treats NULLs as distinct. `(event_id,
 * remind_at)` says the thing actually worth saying - one event cannot have two
 * alerts at the same moment - and it covers both kinds. Two different offsets can
 * never collide, so this does not constrain anything a reader could hit by
 * accident.
 *
 * Written by hand, as every migration here has to be.
 */
final class Version20260823210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow alerts pinned to a moment rather than an offset';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_planning_event_alerts ALTER minutes_before DROP NOT NULL');
        $this->addSql('DROP INDEX uniq_planning_alert_offset');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_planning_alert_moment
              ON core_planning_event_alerts (event_id, remind_at)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_planning_alert_moment');
        // Pinned alerts have no offset to go back to, so they cannot survive the
        // column becoming NOT NULL. Removed rather than given an offset that
        // would fire at a time nobody chose.
        $this->addSql('DELETE FROM core_planning_event_alerts WHERE minutes_before IS NULL');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_planning_alert_offset
              ON core_planning_event_alerts (event_id, minutes_before)
            SQL);
        $this->addSql('ALTER TABLE core_planning_event_alerts ALTER minutes_before SET NOT NULL');
    }
}
