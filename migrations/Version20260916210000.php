<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives a client space its own folder in the library.
 *
 * Until now every file uploaded through a space landed in one shared filing
 * category, `espaces-clients`, and nowhere else: `folder_id` was never set. A
 * studio with two customers therefore had one undifferentiated heap, with
 * nothing on a document saying which space it came from. The only thread back
 * was clicking the document and reading its usage panel, which is a lookup
 * rather than an arrangement.
 *
 * Nullable, because the folder is opened on the space's **first upload** and
 * not with the space. A space that never receives a file would otherwise leave
 * an empty folder behind, and the library is a screen people read: one empty
 * folder per prospect is litter.
 *
 * `SET NULL` rather than `RESTRICT`, because the folder becomes an ordinary
 * library folder the moment it exists. Somebody may bin it, and that must not
 * be a deletion the library refuses for a reason it cannot explain; the next
 * upload opens a fresh one.
 *
 * Nothing is backfilled. Files already filed keep the folder they have, which
 * is none: moving them would mean deciding, for each, whether a document that
 * several spaces attach belongs to one of them. The arrangement starts with the
 * next upload, and the usage panel still answers for what came before.
 */
final class Version20260916210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the library folder a client space files its uploads into';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_customer_spaces ADD document_folder_id INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE core_studio_customer_spaces
            ADD CONSTRAINT FK_2945F8E641491E3 FOREIGN KEY (document_folder_id)
            REFERENCES core_ged_document_folders (id) ON DELETE SET NULL NOT DEFERRABLE
            SQL);
        $this->addSql('CREATE INDEX IDX_2945F8E641491E3 ON core_studio_customer_spaces (document_folder_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_customer_spaces DROP CONSTRAINT IF EXISTS FK_2945F8E641491E3');
        $this->addSql('DROP INDEX IF EXISTS IDX_2945F8E641491E3');
        $this->addSql('ALTER TABLE core_studio_customer_spaces DROP COLUMN IF EXISTS document_folder_id');
    }
}
