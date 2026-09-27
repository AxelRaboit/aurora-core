<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bookings go through the scheduling extension point.
 *
 * A booking gets a row of its own, whose id is the source the calendar keys
 * on: borrowing the page's id made a second booking on the same page collide
 * with the first on the calendar's unique source index.
 *
 * And an event a module announced may now stay editable, which is what a
 * booking needs to be confirmed or cancelled in the calendar. The bookings
 * already there were written straight into the calendar and are marked
 * editable, so they keep answering the way a booking should.
 */
final class Version20260927120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Editorial: bookings; Planning: editable module events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_editorial_booking_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE core_editorial_bookings (id INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, zone_id VARCHAR(36) NOT NULL, start_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, end_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, post_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_booking_zone ON core_editorial_bookings (post_id, zone_id)');
        $this->addSql('CREATE INDEX IDX_7E623A574B89032C ON core_editorial_bookings (post_id)');
        $this->addSql('ALTER TABLE core_editorial_bookings ADD CONSTRAINT FK_7E623A574B89032C FOREIGN KEY (post_id) REFERENCES core_posts (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_planning_events ADD source_editable BOOLEAN DEFAULT false NOT NULL');
        $this->addSql("UPDATE core_planning_events SET source_editable = true WHERE source_type = 'editorial.booking'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_planning_events DROP source_editable');
        $this->addSql('DROP TABLE core_editorial_bookings');
        $this->addSql('DROP SEQUENCE seq_core_editorial_booking_id');
    }
}
