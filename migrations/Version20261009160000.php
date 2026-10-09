<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Remind me of this note » (09/10/2026): one row per person and per
 * reminder, gone with the note or the person.
 */
final class Version20261009160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: reminders on a note';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE seq_core_notes_reminder_id INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE core_notes_reminders (remind_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id INT NOT NULL, user_id INT NOT NULL, note_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_notes_reminder_due ON core_notes_reminders (sent_at, remind_at)');
        $this->addSql('CREATE INDEX IDX_B1D3EEB1A76ED395 ON core_notes_reminders (user_id)');
        $this->addSql('CREATE INDEX IDX_B1D3EEB126ED0855 ON core_notes_reminders (note_id)');
        $this->addSql('ALTER TABLE core_notes_reminders ADD CONSTRAINT FK_B1D3EEB1A76ED395 FOREIGN KEY (user_id) REFERENCES core_users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core_notes_reminders ADD CONSTRAINT FK_B1D3EEB126ED0855 FOREIGN KEY (note_id) REFERENCES core_notes_markdown_notes (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core_notes_reminders');
        $this->addSql('DROP SEQUENCE seq_core_notes_reminder_id');
    }
}
