<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets a calendar be shared with named people.
 *
 * The middle ground the visibility column cannot express: `private` is nobody and
 * `shared` is everybody who can reach the module, and neither says "these three
 * people".
 *
 * Added beside the visibility rather than replacing it. Turning `shared` into a
 * row per account would be the same thing said longer, and it would change who can
 * write to calendars that are shared today - a behaviour nobody asked to change.
 *
 * `can_write` and nothing further. Read and write are the two levels a reader can
 * hold in their head about somebody else's calendar; a third would be an option
 * nobody uses and everybody reads past.
 *
 * Written by hand, as every migration here has to be.
 */
final class Version20260823280000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add core_planning_shares';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_planning_share_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql(<<<'SQL'
            CREATE TABLE core_planning_shares (
              id INT NOT NULL,
              planning_id INT NOT NULL,
              user_id INT NOT NULL,
              can_write BOOLEAN DEFAULT false NOT NULL,
              PRIMARY KEY (id)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_planning_share
              ON core_planning_shares (planning_id, user_id)
            SQL);
        $this->addSql('CREATE INDEX idx_planning_share_user ON core_planning_shares (user_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE core_planning_shares
              ADD CONSTRAINT fk_planning_share_planning
              FOREIGN KEY (planning_id) REFERENCES core_plannings (id)
              ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE core_planning_shares
              ADD CONSTRAINT fk_planning_share_user
              FOREIGN KEY (user_id) REFERENCES core_users (id)
              ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core_planning_shares');
        $this->addSql('DROP SEQUENCE seq_core_planning_share_id');
    }
}
