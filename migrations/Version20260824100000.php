<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replaces `core_plannings.feed_token` with a table of share links.
 *
 * The column could hold one address for one calendar and nothing about it: not
 * when it stops working, not who it was for, not whether it has ever been opened.
 * Those are the questions asked a week later by somebody trying to close it again.
 *
 * **The existing tokens are carried over, not reissued.** A feed token is
 * subscribed to by a phone that will never be told the address changed - it simply
 * goes quiet - so the rows are copied with the same token, `mode = 'ics'` and no
 * expiry, which is exactly what they were. Only then is the column dropped.
 *
 * `expires_at` nullable for that reason: the two kinds of address have genuinely
 * different lifetimes, and one nullable column says both.
 *
 * No `can_write`. Read only for now, and a column claiming otherwise while no
 * write path exists is a trap - somebody flips it and nothing happens.
 *
 * Written by hand, as every migration here has to be.
 */
final class Version20260824100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add core_planning_share_links and absorb core_plannings.feed_token';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_planning_share_link_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql(<<<'SQL'
            CREATE TABLE core_planning_share_links (
              id INT NOT NULL,
              token VARCHAR(64) NOT NULL,
              label VARCHAR(120) DEFAULT '' NOT NULL,
              mode VARCHAR(10) DEFAULT 'web' NOT NULL,
              expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
              revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
              last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              PRIMARY KEY (id)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_planning_share_link_token
              ON core_planning_share_links (token)
            SQL);

        // A link can point at several calendars, which is the other thing the
        // column could not do: a guest usually wants the shoots and the deadlines
        // together rather than two addresses for one schedule.
        //
        // The index and constraint names are Doctrine's own hashes rather than
        // readable ones, and that is deliberate: a join table's names are generated
        // from the mapping and cannot be set from an attribute, so names of my
        // choosing here would be a permanent `schema:update` diff - the exact trap
        // `pitfall_mapping_index_schema_drift` is about. The column names *are*
        // declared on the entity, which is the part that can be.
        $this->addSql(<<<'SQL'
            CREATE TABLE core_planning_share_link_calendars (
              share_link_id INT NOT NULL,
              planning_id INT NOT NULL,
              PRIMARY KEY (share_link_id, planning_id)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_5C69DAB2EFC8A8ED
              ON core_planning_share_link_calendars (share_link_id)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_5C69DAB23D865311
              ON core_planning_share_link_calendars (planning_id)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE core_planning_share_link_calendars
              ADD CONSTRAINT FK_5C69DAB2EFC8A8ED
              FOREIGN KEY (share_link_id) REFERENCES core_planning_share_links (id)
              ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE core_planning_share_link_calendars
              ADD CONSTRAINT FK_5C69DAB23D865311
              FOREIGN KEY (planning_id) REFERENCES core_plannings (id)
              ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);

        // Carry the published feeds over, keeping their tokens so nothing that has
        // subscribed stops resolving. `label` names where they came from, because a
        // row with no label in the new screen would look like a mistake.
        $this->addSql(<<<'SQL'
            INSERT INTO core_planning_share_links (id, token, label, mode, created_at)
            SELECT
              nextval('seq_core_planning_share_link_id'),
              p.feed_token,
              'Feed',
              'ics',
              NOW()
            FROM core_plannings p
            WHERE p.feed_token IS NOT NULL
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO core_planning_share_link_calendars (share_link_id, planning_id)
            SELECT l.id, p.id
            FROM core_planning_share_links l
            INNER JOIN core_plannings p ON p.feed_token = l.token
            SQL);

        $this->addSql('ALTER TABLE core_plannings DROP feed_token');
    }

    /**
     * Puts the column back and returns the `.ics` tokens to it.
     *
     * Lossy on purpose, and worth knowing before rolling back: a calendar reached
     * by several links keeps only one token, web links are dropped entirely, and
     * every expiry and revocation date is lost - the column has nowhere to put any
     * of it. Reversible in shape, not in content.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_plannings ADD feed_token VARCHAR(64) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE core_plannings p
            SET feed_token = sub.token
            FROM (
              SELECT c.planning_id, MIN(l.token) AS token
              FROM core_planning_share_links l
              INNER JOIN core_planning_share_link_calendars c
                ON c.share_link_id = l.id
              WHERE l.mode = 'ics' AND l.revoked_at IS NULL
              GROUP BY c.planning_id
            ) sub
            WHERE sub.planning_id = p.id
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_planning_feed_token ON core_plannings (feed_token)
            SQL);

        $this->addSql('DROP TABLE core_planning_share_link_calendars');
        $this->addSql('DROP TABLE core_planning_share_links');
        $this->addSql('DROP SEQUENCE seq_core_planning_share_link_id');
    }
}
