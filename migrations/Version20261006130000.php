<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A deliverable can be a slideshow.
 *
 * A slide now belongs to a presentation or to a deliverable: `deliverable_id`
 * arrives next to `deck_id`, which becomes nullable while presentations join
 * the deliverables. The deliverable gains the theme and the overrides of its
 * slides, empty for a page.
 *
 * Written by hand: the generated diff also renamed unrelated indexes.
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
        // A deliverable's slides have no presentation to go back to: they are
        // removed before `deck_id` becomes required again.
        $this->addSql('DELETE FROM core_deck_slides WHERE deck_id IS NULL');
        $this->addSql('ALTER TABLE core_deck_slides DROP CONSTRAINT FK_5F851CE8F3C6560A');
        $this->addSql('DROP INDEX IDX_5F851CE8F3C6560A');
        $this->addSql('ALTER TABLE core_deck_slides DROP deliverable_id');
        $this->addSql('ALTER TABLE core_deck_slides ALTER deck_id SET NOT NULL');
        $this->addSql('ALTER TABLE core_studio_deliverables DROP slide_style');
        $this->addSql('ALTER TABLE core_studio_deliverables DROP slide_theme');
    }
}
