<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un livrable peut être un diaporama.
 *
 * Une diapositive appartient désormais à une présentation ou à un livrable :
 * `deliverable_id` arrive à côté de `deck_id`, qui devient nullable le temps
 * que les présentations rejoignent les livrables. Le livrable gagne le thème
 * et les retouches de ses diapositives, vides pour une page.
 *
 * Écrite à la main : le diff généré renommait aussi des index sans rapport.
 */
final class Version20261006130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Studio deliverables: slides belong to a deck or a deliverable, slide theme and style on deliverables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_deck_slides ADD deliverable_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE core_deck_slides ALTER deck_id DROP NOT NULL');
        $this->addSql('ALTER TABLE core_deck_slides ADD CONSTRAINT FK_5F851CE8F3C6560A FOREIGN KEY (deliverable_id) REFERENCES core_studio_deliverables (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_5F851CE8F3C6560A ON core_deck_slides (deliverable_id)');
        $this->addSql('ALTER TABLE core_studio_deliverables ADD slide_theme VARCHAR(20) DEFAULT NULL');
        $this->addSql("ALTER TABLE core_studio_deliverables ADD slide_style JSON DEFAULT '{}' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        // Les diapositives d'un livrable n'ont pas de présentation où revenir :
        // elles partent avant que `deck_id` redevienne obligatoire.
        $this->addSql('DELETE FROM core_deck_slides WHERE deck_id IS NULL');
        $this->addSql('ALTER TABLE core_deck_slides DROP CONSTRAINT FK_5F851CE8F3C6560A');
        $this->addSql('DROP INDEX IDX_5F851CE8F3C6560A');
        $this->addSql('ALTER TABLE core_deck_slides DROP deliverable_id');
        $this->addSql('ALTER TABLE core_deck_slides ALTER deck_id SET NOT NULL');
        $this->addSql('ALTER TABLE core_studio_deliverables DROP slide_style');
        $this->addSql('ALTER TABLE core_studio_deliverables DROP slide_theme');
    }
}
