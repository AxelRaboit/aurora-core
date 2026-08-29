<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets a publication come down on a date, as it already goes up on one.
 *
 * `scheduled_at` put a post live without anybody present; nothing took it off
 * again. An offer that expires, a notice that stops applying, a page written for
 * one season - all of them relied on somebody remembering, which is the kind of
 * task that gets remembered late or not at all.
 *
 * Written by hand, as every migration here has to be.
 */
final class Version20260824130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unpublish_at to core_posts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts ADD unpublish_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts DROP unpublish_at');
    }
}
