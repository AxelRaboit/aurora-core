<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Écrire dans la discussion devient un droit à part.
 *
 * Un seul droit commandait deux conversations : commenter une fiche, et parler
 * dans le salon de l'espace. Cocher une case pour autoriser une remarque sous
 * une publication ouvrait donc aussi le fil de la relation.
 *
 * La colonne est recopiée depuis « peut commenter » plutôt que posée à vrai :
 * un lien déjà dehors se comporte exactement comme avant, y compris celui qui
 * ne commentait pas et qui ne doit pas se mettre à pouvoir parler.
 */
final class Version20260920180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Writing in the space conversation becomes its own right on a link';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_access_links ADD can_chat BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('UPDATE core_studio_space_access_links SET can_chat = can_comment');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_access_links DROP can_chat');
    }
}
