<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add accent_color to posts: a publication can now choose its own accent
 * colour, overriding the active theme's for that page only. Nullable - null
 * means inherit from the theme, the same contract as the three colours added
 * in Version20260831100000.
 */
final class Version20260926090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add accent_color to core_posts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts ADD accent_color VARCHAR(7) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts DROP accent_color');
    }
}
