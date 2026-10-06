<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les diapositives prennent le nom de leur seul propriétaire : le livrable.
 *
 * Le moteur de diapositives a quitté `Studio/Deck` pour `Deliverable/Slides`,
 * et sa table suit, renommée et pas recréée : les identifiants restent, ceux
 * que nomment déjà les zones et les liens aussi. La séquence suit la table.
 */
final class Version20261006150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Studio slides: core_deck_slides becomes core_studio_deliverable_slides, with its sequence';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_deck_slides RENAME TO core_studio_deliverable_slides');
        $this->addSql('ALTER SEQUENCE seq_core_deck_slide_id RENAME TO seq_core_deliverable_slide_id');
        $this->addSql('ALTER INDEX core_deck_slides_pkey RENAME TO core_studio_deliverable_slides_pkey');
        $this->addSql('ALTER INDEX idx_5f851ce8f3c6560a RENAME TO IDX_FE195464F3C6560A');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX IDX_FE195464F3C6560A RENAME TO idx_5f851ce8f3c6560a');
        $this->addSql('ALTER INDEX core_studio_deliverable_slides_pkey RENAME TO core_deck_slides_pkey');
        $this->addSql('ALTER SEQUENCE seq_core_deliverable_slide_id RENAME TO seq_core_deck_slide_id');
        $this->addSql('ALTER TABLE core_studio_deliverable_slides RENAME TO core_deck_slides');
    }
}
