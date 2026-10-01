<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un contrat peut porter un texte adapté pour son client.
 *
 * Le texte d'une partie (corps, annexe) modifié pour ce contrat seul, sans
 * créer de trame : il est scellé avec le contrat et disparaît avec lui. Vide
 * pour tous les contrats existants, qui gardent le texte de leur trame.
 */
final class Version20261001090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contracts: wording adapted for one contract';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE core_contracts ADD adapted_wording JSON DEFAULT '{}' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_contracts DROP adapted_wording');
    }
}
