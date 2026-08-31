<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add color overrides to posts: header, footer, and background colors can now
 * be set per-post to override the active theme's colors for that specific
 * publication. All columns are nullable - null means inherit from theme.
 */
final class Version20260831100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add header_color, footer_color, background_color to core_posts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts ADD header_color VARCHAR(7) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_posts ADD footer_color VARCHAR(7) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_posts ADD background_color VARCHAR(7) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts DROP header_color');
        $this->addSql('ALTER TABLE core_posts DROP footer_color');
        $this->addSql('ALTER TABLE core_posts DROP background_color');
    }
}
