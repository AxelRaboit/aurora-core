<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Deux choses que la vague de blocs de septembre 2026 garde en base.
 *
 * Les réglages de l'appareil photo d'une image, lus au dépôt avant que le
 * fichier ne perde ses métadonnées au réencodage : `{}` pour tout ce qui est
 * déjà là, dont le fichier ne sait plus rien.
 *
 * Et les votes des sondages : un par lecteur et par sondage, repéré par une
 * empreinte plutôt que par une adresse ou un compte, pour compter sans rien
 * garder qui désigne quelqu'un.
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
