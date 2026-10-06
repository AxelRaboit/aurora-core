<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A post can be written for a customer space.
 *
 * An audit or a strategy delivered to a client, written from their space. An
 * id rather than a foreign key: the site module does not depend on the agency
 * one, the same way a deck zone names its deck. Deleting the space empties
 * the column, and the post stays. Empty for every existing post.
 */
final class Version20261001160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Posts: the customer space a publication was written for';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts ADD customer_space_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_core_posts_customer_space ON core_posts (customer_space_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_core_posts_customer_space');
        $this->addSql('ALTER TABLE core_posts DROP customer_space_id');
    }
}
