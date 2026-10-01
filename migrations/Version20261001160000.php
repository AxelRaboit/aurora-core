<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Une publication peut être écrite pour un espace client.
 *
 * Un audit ou une stratégie livré à un client, rédigé depuis son espace. Un
 * identifiant plutôt qu'une clé étrangère : le module du site ne dépend pas de
 * celui de l'agence, comme une zone de deck nomme son deck. Supprimer l'espace
 * remet la colonne à vide, et la publication reste. Vide pour toutes les
 * publications existantes.
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
