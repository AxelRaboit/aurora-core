<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A customer space points at the note space its team writes in.
 *
 * Nullable and `SET NULL`, like the space's library folder: the note space is
 * opened the first time somebody needs it, and unique, because one note space
 * holds the notes of one customer space only.
 */
final class Version20261006180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Studio: customer spaces point at their note space';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_customer_spaces ADD note_space_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE core_studio_customer_spaces ADD CONSTRAINT FK_2945F8E6330C8FDE FOREIGN KEY (note_space_id) REFERENCES core_notes_spaces (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2945F8E6330C8FDE ON core_studio_customer_spaces (note_space_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_2945F8E6330C8FDE');
        $this->addSql('ALTER TABLE core_studio_customer_spaces DROP CONSTRAINT FK_2945F8E6330C8FDE');
        $this->addSql('ALTER TABLE core_studio_customer_spaces DROP note_space_id');
    }
}
