<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add share_enabled to posts: a publication can now drop the share buttons at
 * the bottom of its page. Defaults to true, so every existing page keeps them
 * until someone unticks the box.
 */
final class Version20260926190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add share_enabled to core_posts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts ADD share_enabled BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts DROP share_enabled');
    }
}
