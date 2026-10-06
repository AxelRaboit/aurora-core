<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Two things the September 2026 wave of blocks keeps in the database.
 *
 * An image's camera settings, read at upload before the file loses its
 * metadata when re-encoded: `{}` for everything already there, whose file no
 * longer knows anything.
 *
 * And the poll votes: one per reader and per poll, identified by a
 * fingerprint rather than by an address or an account, to count without
 * keeping anything that points to someone.
 */
final class Version20260925180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ged: camera settings of a photograph; Editorial: poll votes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE core_ged_documents ADD exif JSON DEFAULT '{}' NOT NULL");
        $this->addSql('CREATE SEQUENCE seq_core_editorial_poll_vote_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE core_editorial_poll_votes (id INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, zone_id VARCHAR(36) NOT NULL, answer SMALLINT NOT NULL, voter VARCHAR(64) NOT NULL, post_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_poll_vote_zone ON core_editorial_poll_votes (post_id, zone_id)');
        // The unique index doubles as the guard against voting twice: the
        // insert fails rather than the controller having to check first.
        $this->addSql('CREATE UNIQUE INDEX uniq_poll_vote_voter ON core_editorial_poll_votes (post_id, zone_id, voter)');
        $this->addSql('CREATE INDEX IDX_8992AFF94B89032C ON core_editorial_poll_votes (post_id)');
        $this->addSql('ALTER TABLE core_editorial_poll_votes ADD CONSTRAINT FK_8992AFF94B89032C FOREIGN KEY (post_id) REFERENCES core_posts (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core_editorial_poll_votes');
        $this->addSql('DROP SEQUENCE seq_core_editorial_poll_vote_id');
        $this->addSql('ALTER TABLE core_ged_documents DROP exif');
    }
}
