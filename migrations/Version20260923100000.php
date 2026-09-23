<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Une note peut porter une image d'entête et une apparence.
 *
 * L'image reste chez celui qui l'héberge : on ne garde que son adresse, le
 * nom du photographe et le lien vers sa page, parce que la licence demande
 * de créditer et qu'une fois l'image hors de la médiathèque il n'y a plus
 * qu'ici pour le faire. Rien n'entre dans la GED, exprès.
 *
 * `appearance` vaut `plain` par défaut : aucune note existante ne change
 * d'allure le jour de la migration.
 */
final class Version20260923100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes: a cover image and an appearance on a note';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD cover_url VARCHAR(1024) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD cover_credit_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD cover_credit_url VARCHAR(1024) DEFAULT NULL');
        $this->addSql('ALTER TABLE core_notes_markdown_notes ADD cover_position INT DEFAULT 50 NOT NULL');
        $this->addSql("ALTER TABLE core_notes_markdown_notes ADD appearance VARCHAR(20) DEFAULT 'plain' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP cover_url');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP cover_credit_name');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP cover_credit_url');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP cover_position');
        $this->addSql('ALTER TABLE core_notes_markdown_notes DROP appearance');
    }
}
