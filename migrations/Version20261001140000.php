<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les liens de lecture d'une publication, et la façon dont leur page se présente.
 *
 * Un lien ouvre une publication hors du site, sans compte : un par
 * destinataire, avec un intitulé, une date d'expiration et un mot de passe
 * facultatifs, et le nombre d'ouvertures. Révoquer pose une date, sans jamais
 * supprimer la ligne.
 *
 * `reading_page` dit ce que la page de lecture affiche en tête : pour qui le
 * document a été préparé, la date, le logo du site. Vide pour toutes les
 * publications existantes, ce qui donne les valeurs par défaut.
 */
final class Version20261001140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Posts: reading links, and how their page introduces itself';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_post_reading_link_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql("CREATE TABLE core_post_reading_links (id INT NOT NULL, post_id INT NOT NULL, token VARCHAR(64) NOT NULL, label VARCHAR(120) DEFAULT '' NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, open_count INT DEFAULT 0 NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))");
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8DAAA8005F37A13B ON core_post_reading_links (token)');
        $this->addSql('CREATE INDEX idx_core_post_reading_links_post ON core_post_reading_links (post_id)');
        $this->addSql('ALTER TABLE core_post_reading_links ADD CONSTRAINT FK_8DAAA8004B89032C FOREIGN KEY (post_id) REFERENCES core_posts (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql("ALTER TABLE core_posts ADD reading_page JSON DEFAULT '{}' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts DROP reading_page');
        $this->addSql('DROP TABLE core_post_reading_links');
        $this->addSql('DROP SEQUENCE seq_core_post_reading_link_id');
    }
}
