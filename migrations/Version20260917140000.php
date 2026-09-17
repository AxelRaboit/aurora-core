<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Des notes sur un espace, et la seule chose que le client n'y voit pas.
 *
 * Écrite à la main, comme celles qui précèdent : `migrations:diff` sur cette
 * base de développement propose aussi de renommer deux douzaines d'index qu'il
 * n'a pas créés.
 *
 * Le corps est du `json` et pas du `text` : c'est une structure, et la seule
 * requête qui regarde dedans - quelles notes portent cette image - passe par un
 * `::text` explicite, faute d'opérateur `LIKE` sur `json` côté Postgres.
 *
 * L'index composite est celui que l'écran lit : les notes d'un espace, épinglées
 * d'abord. Celui sur `space_id` seul est celui de Doctrine, pour la clé
 * étrangère.
 *
 * `author_id` est `SET NULL` : supprimer un compte ne doit pas supprimer ce
 * qu'il a écrit, et le nom durable voyage dans `author_label`.
 */
final class Version20260917140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes on a client space';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_space_note_id INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql('CREATE TABLE core_studio_space_notes (id INT NOT NULL, space_id INT NOT NULL, author_id INT DEFAULT NULL, title VARCHAR(180) NOT NULL, body JSON NOT NULL, colour_slot INT DEFAULT NULL, pinned BOOLEAN DEFAULT false NOT NULL, author_label VARCHAR(180) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');

        $this->addSql('CREATE INDEX idx_space_note_space_pinned ON core_studio_space_notes (space_id, pinned)');
        $this->addSql('CREATE INDEX IDX_3321E48123575340 ON core_studio_space_notes (space_id)');
        $this->addSql('CREATE INDEX IDX_3321E481F675F31B ON core_studio_space_notes (author_id)');

        $this->addSql('ALTER TABLE core_studio_space_notes ADD CONSTRAINT FK_3321E48123575340 FOREIGN KEY (space_id) REFERENCES core_studio_customer_spaces (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_studio_space_notes ADD CONSTRAINT FK_3321E481F675F31B FOREIGN KEY (author_id) REFERENCES core_users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_notes DROP CONSTRAINT FK_3321E481F675F31B');
        $this->addSql('ALTER TABLE core_studio_space_notes DROP CONSTRAINT FK_3321E48123575340');
        $this->addSql('DROP TABLE core_studio_space_notes');
        $this->addSql('DROP SEQUENCE seq_core_space_note_id CASCADE');
    }
}
