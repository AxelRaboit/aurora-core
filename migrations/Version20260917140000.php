<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notes on a space, and the only thing the client does not see there.
 *
 * Written by hand, like the ones before it: `migrations:diff` on this
 * development database also offers to rename two dozen indexes it did not
 * create.
 *
 * The body is `json` and not `text`: it is a structure, and the only query
 * that looks inside - which notes carry this image - goes through an explicit
 * `::text`, for lack of a `LIKE` operator on `json` in Postgres.
 *
 * The composite index is the one the screen reads: a space's notes, pinned
 * first. The one on `space_id` alone is Doctrine's, for the foreign key.
 *
 * `author_id` is `SET NULL`: deleting an account must not delete what it
 * wrote, and the lasting name travels in `author_label`.
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
