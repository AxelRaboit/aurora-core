<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add highlight and highlight_color to posts: a publication can now choose
 * how its hovers and card markers are coloured (accent, neutral or custom),
 * overriding the active theme's for that page only. Nullable - null means
 * inherit from the theme, the same contract as accent_color.
 */
final class Version20260926180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add highlight and highlight_color to core_posts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts ADD highlight VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_posts ADD highlight_color VARCHAR(7) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts DROP highlight');
        $this->addSql('ALTER TABLE core_posts DROP highlight_color');
    }
}
