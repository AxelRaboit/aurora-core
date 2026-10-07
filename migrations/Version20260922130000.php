<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The date by which a client must have answered.
 *
 * **One more column, and not a misuse of `scheduled_at`.** A post planned for
 * the 30th is not approved on the 30th: it takes time to produce and sometimes
 * to rework. The two dates answer two different questions, and mixing them up
 * means finding out the day before that a review was missing.
 *
 * Nullable, because most cards do not carry one: a required column would have
 * forced inventing a deadline at every creation. Nothing hooks into it on the
 * application side - no blocking, no unscheduling - so a past value does
 * nothing but show up.
 *
 * Written by hand rather than with `doctrine:migrations:diff`: the automatic
 * generation picked up a pre-existing index drift and would have dropped about
 * ten indexes that have nothing to do with this change.
 */
final class Version20260922130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A review deadline on a space content item, distinct from its publication date';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_content_items ADD review_by TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_content_items DROP review_by');
    }
}
