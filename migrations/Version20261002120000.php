<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les livrables d'un espace client quittent les publications.
 *
 * Un audit, une stratégie, un bilan écrits pour un client deviennent une table
 * à eux, à côté de l'espace : la même grille de zones que les pages du site,
 * une langue, une apparence, une case « visible par le client », et leurs
 * propres liens de lecture. Les publications perdent la colonne qui les
 * rattachait à un espace.
 *
 * Aucune publication n'était rattachée à un espace en production au moment de
 * ce changement (0.9.322) : rien à recopier. Une installation qui en aurait
 * les perd de vue dans l'espace, et les garde dans les publications, partagées
 * par lien.
 */
final class Version20261002120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Studio: space deliverables and their reading links, out of the posts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_space_deliverable_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE seq_core_space_deliverable_link_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql("CREATE TABLE core_studio_space_deliverables (title VARCHAR(255) NOT NULL, summary TEXT DEFAULT NULL, locale VARCHAR(8) NOT NULL, grid_layout JSON DEFAULT '{}' NOT NULL, grid_content JSON DEFAULT '{}' NOT NULL, appearance JSON DEFAULT '{}' NOT NULL, reading_header JSON DEFAULT '{}' NOT NULL, visible_to_client BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id INT NOT NULL, space_id INT NOT NULL, PRIMARY KEY (id))");
        $this->addSql('CREATE INDEX idx_space_deliverable_space ON core_studio_space_deliverables (space_id)');
        $this->addSql("CREATE TABLE core_studio_space_deliverable_links (token VARCHAR(64) NOT NULL, label VARCHAR(120) DEFAULT '' NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, open_count INT DEFAULT 0 NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id INT NOT NULL, deliverable_id INT NOT NULL, PRIMARY KEY (id))");
        $this->addSql('CREATE UNIQUE INDEX UNIQ_346BB9685F37A13B ON core_studio_space_deliverable_links (token)');
        $this->addSql('CREATE INDEX idx_space_deliverable_link_deliverable ON core_studio_space_deliverable_links (deliverable_id)');
        $this->addSql('ALTER TABLE core_studio_space_deliverables ADD CONSTRAINT FK_8880990323575340 FOREIGN KEY (space_id) REFERENCES core_studio_customer_spaces (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_studio_space_deliverable_links ADD CONSTRAINT FK_346BB968F3C6560A FOREIGN KEY (deliverable_id) REFERENCES core_studio_space_deliverables (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('DROP INDEX idx_core_posts_customer_space');
        $this->addSql('ALTER TABLE core_posts DROP customer_space_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts ADD customer_space_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_core_posts_customer_space ON core_posts (customer_space_id)');
        $this->addSql('ALTER TABLE core_studio_space_deliverable_links DROP CONSTRAINT FK_346BB968F3C6560A');
        $this->addSql('ALTER TABLE core_studio_space_deliverables DROP CONSTRAINT FK_8880990323575340');
        $this->addSql('DROP TABLE core_studio_space_deliverable_links');
        $this->addSql('DROP TABLE core_studio_space_deliverables');
        $this->addSql('DROP SEQUENCE seq_core_space_deliverable_link_id CASCADE');
        $this->addSql('DROP SEQUENCE seq_core_space_deliverable_id CASCADE');
    }
}
