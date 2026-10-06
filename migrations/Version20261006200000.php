<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One client-visibility rule across a customer space: what can be shown to
 * the client is hidden when it is created.
 *
 * - **Board steps** (`core_studio_space_content_columns.visible_to_client`):
 *   the column default goes from true to false. Existing steps keep their
 *   state; only new rows change.
 * - **Space files** (`core_studio_space_files.visible_to_client`): a new
 *   column. The rows that exist were all read by the client until now, so
 *   they are written true, then the default becomes false for the rows to
 *   come. Nothing the client already saw disappears on the day of the update.
 *
 * Resources, deliverables and chat channels were already hidden by default
 * and are not touched.
 */
final class Version20261006200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Steps and space files are hidden from the client by default; existing space files stay visible';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_content_columns ALTER visible_to_client SET DEFAULT false');

        // True for the rows already there, false for the rows to come.
        $this->addSql('ALTER TABLE core_studio_space_files ADD visible_to_client BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE core_studio_space_files ALTER visible_to_client SET DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_files DROP visible_to_client');
        $this->addSql('ALTER TABLE core_studio_space_content_columns ALTER visible_to_client SET DEFAULT true');
    }
}
