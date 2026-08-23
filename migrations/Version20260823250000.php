<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets an alert arrive by email, and a reminder too.
 *
 * An alert that only shows inside the application is half an alert: you see it
 * when you are already looking, which is the moment you needed it least.
 *
 * The channel is per alert rather than per calendar, which is how Google puts it -
 * the same meeting often wants a notification ten minutes before and an email the
 * day before, and one switch on the calendar can express neither.
 *
 * The unique index moves with it. `(event_id, remind_at)` was right while an alert
 * was only a moment; with channels, "tell me and email me, both thirty minutes
 * before" is two legitimate rows at the same instant, so the channel joins the key.
 *
 * Reminders get the column too. They have no alert rows - a reminder announces
 * itself at its due time - so the choice lives on the reminder.
 *
 * Written by hand, as every migration here has to be.
 */
final class Version20260823250000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a delivery channel to alerts and reminders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE core_planning_event_alerts
              ADD channel VARCHAR(20) DEFAULT 'notification' NOT NULL
            SQL);

        $this->addSql('DROP INDEX uniq_planning_alert_moment');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_planning_alert_moment
              ON core_planning_event_alerts (event_id, remind_at, channel)
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE core_planning_reminders
              ADD channel VARCHAR(20) DEFAULT 'notification' NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_planning_reminders DROP channel');

        $this->addSql('DROP INDEX uniq_planning_alert_moment');
        // Two alerts at one instant on different channels cannot both survive the
        // narrower key. The email one goes, because the notification is the
        // channel that existed before this column did.
        $this->addSql(<<<'SQL'
            DELETE FROM core_planning_event_alerts
             WHERE channel <> 'notification'
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_planning_alert_moment
              ON core_planning_event_alerts (event_id, remind_at)
            SQL);
        $this->addSql('ALTER TABLE core_planning_event_alerts DROP channel');
    }
}
