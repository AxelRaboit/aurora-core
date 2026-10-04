<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La table des instances déployées, alimentée par le beacon.
 *
 * Un enregistrement par instance (clé unique `instance_id`), mis à jour à
 * chaque ping plutôt qu'ajouté : la table reste de la taille du nombre de
 * déploiements vivants. Sert à repérer une mise en production non autorisée du
 * code (voir LICENSE).
 */
final class Version20261003180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Beacon: deployed instances table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_beacon_instance_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE beacon_instances (
            id INT NOT NULL,
            instance_id VARCHAR(64) NOT NULL,
            domain VARCHAR(255) DEFAULT NULL,
            hostname VARCHAR(255) DEFAULT NULL,
            app_version VARCHAR(40) DEFAULT NULL,
            php_version VARCHAR(20) DEFAULT NULL,
            signature_valid BOOLEAN DEFAULT false NOT NULL,
            known BOOLEAN DEFAULT false NOT NULL,
            ping_count INT DEFAULT 1 NOT NULL,
            last_ip VARCHAR(45) DEFAULT NULL,
            first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_beacon_instance_id ON beacon_instances (instance_id)');
        $this->addSql("COMMENT ON COLUMN beacon_instances.first_seen_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN beacon_instances.last_seen_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE beacon_instances');
        $this->addSql('DROP SEQUENCE seq_beacon_instance_id');
    }
}
