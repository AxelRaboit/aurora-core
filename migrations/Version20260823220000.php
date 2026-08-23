<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets one event carry a colour of its own.
 *
 * Nullable, and null is the common case: a calendar's colour is how you tell
 * whose an event is at a glance, so an event that quietly picked its own would
 * break that reading for everything around it. The override exists for the one
 * meeting in the week that has to stand out, which is what Google offers too.
 *
 * Null rather than a copy of the calendar's slot, because they are different
 * statements: "follow the calendar" has to keep following it when the calendar's
 * colour changes, and a copied number would not.
 *
 * Written by hand, as every migration here has to be.
 */
final class Version20260823220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add colour_slot to core_planning_events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_planning_events ADD colour_slot INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_planning_events DROP colour_slot');
    }
}
