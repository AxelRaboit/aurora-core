<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Board steps keep two roles: review and published.
 *
 * Idea, production and scheduled were offered and decided nothing - the counts
 * of the dashboard and the editorial calendar never read them. The steps that
 * carried them keep their name and place, without a role.
 */
final class Version20261006110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Space content columns: drop the idea, production and scheduled roles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE core_studio_space_content_columns SET role = NULL WHERE role IN ('idea', 'production', 'scheduled')");
    }

    public function down(Schema $schema): void
    {
        // Nothing to give back: which step carried which of the three is not
        // kept anywhere, and a role is optional either way.
    }
}
