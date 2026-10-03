<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'apparence d'une publication rejoint celle du thème.
 *
 * `color_overrides` porte les couleurs du thème qu'une publication repeint en
 * plus du fond, de la topbar et du pied : texte, traits, cartes, titres,
 * chiffres. Vide par défaut, donc chaque publication garde le rendu qu'elle
 * avait. `chrome_follows_page` dit si la topbar et le pied prennent l'accent
 * et les survols de la page ; faux par défaut, pour la même raison.
 */
final class Version20261003130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Posts: more theme colours per post, and topbar and footer following the page';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE core_posts ADD color_overrides JSON DEFAULT '{}' NOT NULL");
        $this->addSql('ALTER TABLE core_posts ADD chrome_follows_page BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts DROP color_overrides');
        $this->addSql('ALTER TABLE core_posts DROP chrome_follows_page');
    }
}
