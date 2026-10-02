<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le réglage « Format des dates » devient un style : courte, moyenne, longue.
 *
 * Il contenait un motif PHP (`d/m/Y` par défaut) que rien ne lisait, et qui
 * n'aurait pas pu servir trois langues. Les dates des emails, des PDF et des
 * pages de contrat suivent désormais la langue du document, dans le style
 * choisi ; `d/m/Y` décrivait la courte, c'est donc elle qu'il devient. Un
 * motif inconnu se lit aussi comme la courte, migration passée ou non.
 */
final class Version20261002190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Settings: the date format becomes a style (short, medium, long)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE core_settings SET value = 'short', setting_type = 'select' WHERE setting_key = 'date_format' AND (value IS NULL OR value NOT IN ('short', 'medium', 'long'))");
        $this->addSql("UPDATE core_settings SET setting_type = 'select' WHERE setting_key = 'date_format'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE core_settings SET value = 'd/m/Y', setting_type = 'string' WHERE setting_key = 'date_format'");
    }
}
