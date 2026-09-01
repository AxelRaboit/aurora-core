<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Give a post type somewhere to say what it is for.
 *
 * It was the only one of Editorial's four record families with no description
 * of its own, so the side menu could only ever show it a count of its posts
 * where the others show a sentence. Nullable: every post type that exists
 * predates the column, and a required sentence would have to be invented for
 * each of them on the way in.
 */
final class Version20260901070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add description to core_post_types';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_post_types ADD description TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_post_types DROP description');
    }
}
