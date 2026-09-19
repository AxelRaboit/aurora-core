<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Une conversation privée rangée par l'un reste entière pour l'autre.
 *
 * La colonne est portée par la personne et non par le salon : ranger une
 * conversation est un geste de classement, pas une suppression, et l'autre
 * côté continue de la voir. Nulle partout au départ, ce qui est l'état de
 * toutes les conversations existantes - aucune n'a été rangée.
 */
final class Version20260918210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A private conversation put away by one person stays whole for the other';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_chat_channel_members ADD hidden_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_chat_channel_members DROP COLUMN hidden_at');
    }
}
