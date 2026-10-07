<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Deliverables leave the client space: a Studio deliverable has no space, it
 * has an author and a scope (personal or shared).
 *
 * The tables lose their "space" prefix along with the module, renamed and
 * not recreated: the deliverables and their read links already sent stay
 * what they are, tokens included. Every existing deliverable belongs to a
 * space and stays shared, with no known author.
 */
final class Version20261004090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Studio deliverables without a customer space: owner and scope, tables renamed with the module';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_deliverables RENAME TO core_studio_deliverables');
        $this->addSql('ALTER TABLE core_studio_space_deliverable_links RENAME TO core_studio_deliverable_links');
        $this->addSql('ALTER SEQUENCE seq_core_space_deliverable_id RENAME TO seq_core_deliverable_id');
        $this->addSql('ALTER SEQUENCE seq_core_space_deliverable_link_id RENAME TO seq_core_deliverable_link_id');
        $this->addSql('ALTER INDEX core_studio_space_deliverables_pkey RENAME TO core_studio_deliverables_pkey');
        $this->addSql('ALTER INDEX core_studio_space_deliverable_links_pkey RENAME TO core_studio_deliverable_links_pkey');
        $this->addSql('ALTER INDEX idx_space_deliverable_space RENAME TO idx_deliverable_space');
        $this->addSql('ALTER INDEX idx_space_deliverable_link_deliverable RENAME TO idx_deliverable_link_deliverable');

        $this->addSql('ALTER TABLE core_studio_deliverables ALTER space_id DROP NOT NULL');
        $this->addSql('ALTER TABLE core_studio_deliverables ADD owner_id INT DEFAULT NULL');
        $this->addSql("ALTER TABLE core_studio_deliverables ADD scope VARCHAR(16) DEFAULT 'shared' NOT NULL");
        $this->addSql('CREATE INDEX idx_deliverable_owner ON core_studio_deliverables (owner_id)');
        $this->addSql('ALTER TABLE core_studio_deliverables ADD CONSTRAINT FK_154B06C37E3C61F9 FOREIGN KEY (owner_id) REFERENCES core_users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // A deliverable without a space has nowhere to go back to: they are removed.
        $this->addSql('DELETE FROM core_studio_deliverables WHERE space_id IS NULL');
        $this->addSql('ALTER TABLE core_studio_deliverables DROP CONSTRAINT FK_154B06C37E3C61F9');
        $this->addSql('DROP INDEX idx_deliverable_owner');
        $this->addSql('ALTER TABLE core_studio_deliverables DROP scope');
        $this->addSql('ALTER TABLE core_studio_deliverables DROP owner_id');
        $this->addSql('ALTER TABLE core_studio_deliverables ALTER space_id SET NOT NULL');

        $this->addSql('ALTER INDEX idx_deliverable_link_deliverable RENAME TO idx_space_deliverable_link_deliverable');
        $this->addSql('ALTER INDEX idx_deliverable_space RENAME TO idx_space_deliverable_space');
        $this->addSql('ALTER INDEX core_studio_deliverable_links_pkey RENAME TO core_studio_space_deliverable_links_pkey');
        $this->addSql('ALTER INDEX core_studio_deliverables_pkey RENAME TO core_studio_space_deliverables_pkey');
        $this->addSql('ALTER SEQUENCE seq_core_deliverable_link_id RENAME TO seq_core_space_deliverable_link_id');
        $this->addSql('ALTER SEQUENCE seq_core_deliverable_id RENAME TO seq_core_space_deliverable_id');
        $this->addSql('ALTER TABLE core_studio_deliverable_links RENAME TO core_studio_space_deliverable_links');
        $this->addSql('ALTER TABLE core_studio_deliverables RENAME TO core_studio_space_deliverables');
    }
}
