<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Records what a reviewer decided, and why.
 *
 * `pending_review` existed as a status and as a badge colour, and as nothing else:
 * a publication was demoted into it silently, nobody was told, and the only way
 * back out was for somebody to notice and change the select. That is not a
 * workflow, it is a dead end with a label.
 *
 * The note lives on the post rather than in a revision because it is about the
 * *current* draft: an author reopening it needs to read what to change before
 * anything else. It is cleared on resubmission, since a note about a version that
 * no longer exists is worse than no note.
 *
 * `SET NULL` on the reviewer: deleting an account must not delete the reasoning it
 * left behind, and an unattributed note is still the note.
 *
 * Written by hand, as every migration here has to be.
 */
final class Version20260824120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add review note, date and reviewer to core_posts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts ADD review_note TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE core_posts ADD reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE core_posts ADD reviewed_by_id INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE core_posts
              ADD CONSTRAINT FK_DEDC9B1AFC6B21F1
              FOREIGN KEY (reviewed_by_id) REFERENCES core_users (id)
              ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            SQL);
        $this->addSql('CREATE INDEX IDX_DEDC9B1AFC6B21F1 ON core_posts (reviewed_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts DROP CONSTRAINT FK_DEDC9B1AFC6B21F1');
        $this->addSql('DROP INDEX IDX_DEDC9B1AFC6B21F1');
        $this->addSql('ALTER TABLE core_posts DROP review_note');
        $this->addSql('ALTER TABLE core_posts DROP reviewed_at');
        $this->addSql('ALTER TABLE core_posts DROP reviewed_by_id');
    }
}
