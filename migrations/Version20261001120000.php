<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Une publication peut être lue par un lien seulement.
 *
 * « site » pour toutes les publications existantes, qui restent sur le site
 * exactement comme avant. « link » sort une publication du site : plus
 * d'adresse sous le site, plus de liste, de menu, de sitemap ni de recherche.
 */
final class Version20261001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Posts: visibility (on the site, or by link only)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE core_posts ADD visibility VARCHAR(20) DEFAULT 'site' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts DROP visibility');
    }
}
