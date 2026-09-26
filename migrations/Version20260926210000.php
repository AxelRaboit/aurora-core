<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename core_ged_documents.variants to renditions. "Variant" now names a
 * document declined from another one (a colour version of a visual), so the
 * resized copies generated at upload take the name image libraries give
 * them. Only the column moves: the stored files keep their `variants/`
 * directory.
 */
final class Version20260926210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename core_ged_documents.variants to renditions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_ged_documents RENAME COLUMN variants TO renditions');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_ged_documents RENAME COLUMN renditions TO variants');
    }
}
