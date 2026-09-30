<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un numéro de version sur chaque note.
 *
 * L'éditeur enregistre tout seul : à deux sur une même note, le dernier qui
 * tapait écrasait l'autre sans que personne le sache. Le numéro avance à
 * chaque écriture du contenu, et un enregistrement parti d'une version
 * dépassée est refusé au lieu d'écraser. C'est le préalable d'un espace
 * d'équipe où plusieurs personnes écrivent.
 *
 * Toutes les notes partent de 1 : rien ne change pour qui écrit seul.
 */
final class Version20260930120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: a version number that refuses a save made from an outdated copy';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP version');
    }
}
