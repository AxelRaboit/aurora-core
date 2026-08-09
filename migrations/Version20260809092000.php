<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The featured image becomes the thumbnail, and gains a fit and a focal point.
 *
 * It was three things at once: the picture a listing card shows, the image a
 * shared link previews, and a second hero printed at the top of the page under
 * the custom header. The third stopped making sense the day the header could
 * carry its own picture — the page rendered both, one above the other. What is
 * left is what the new name says.
 *
 * `RENAME COLUMN`, not drop-and-add: every post that has a picture keeps it.
 * A drop would have been shorter to write and would have silently emptied the
 * column on every install that already had content.
 *
 * The two focal columns are nullable and mean "use the document's". The
 * document's answer is about the file; this one is about this publication's
 * card, which is a different question the moment a wide photo has to work in a
 * narrow frame.
 *
 * Written by hand, as with every migration in this module: `migrations:diff`
 * still wants to drop the tables of the extracted modules, and would bury
 * these four statements under hundreds that must not run — and it would have
 * written the rename as a drop and an add, losing the data.
 */
final class Version20260809092000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename the featured image to thumbnail and give it a fit and a focal point';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts RENAME COLUMN featured_media_id TO thumbnail_id');
        // DEFAULT is required, not cosmetic: the table already has rows, and
        // PostgreSQL refuses to add a NOT NULL column to them without one.
        $this->addSql("ALTER TABLE core_posts ADD thumbnail_fit VARCHAR(20) DEFAULT 'cover' NOT NULL");
        $this->addSql('ALTER TABLE core_posts ADD thumbnail_focal_x DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE core_posts ADD thumbnail_focal_y DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts DROP thumbnail_focal_y');
        $this->addSql('ALTER TABLE core_posts DROP thumbnail_focal_x');
        $this->addSql('ALTER TABLE core_posts DROP thumbnail_fit');
        $this->addSql('ALTER TABLE core_posts RENAME COLUMN thumbnail_id TO featured_media_id');
    }
}
