<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A third right on a space access link: may the holder send a file.
 *
 * **`DEFAULT false`, where `can_approve` and `can_comment` both default to
 * true, and the difference is the whole point of writing this migration by
 * hand.** Answering and commenting are what a link is usually for, so they
 * arrive on. Uploading writes bytes into the application's own storage from an
 * address with no account behind it; granting that to every link that already
 * exists, on the day this deploys, is not a default anybody chose. Existing
 * rows therefore keep the answer they would have given before the column
 * existed, and the studio turns it on for the client who has photos to send.
 *
 * The column says *who*. What may actually be sent is `SpaceGuestUploadPolicy`
 * and lives in the application, because it is a judgement about bytes rather
 * than a fact about a row: an allow-list of inert types, checked against the
 * sniffed mime type rather than the one the browser declared, and a ceiling on
 * size.
 */
final class Version20260916160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Whether a space access link may send a file';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_access_links ADD can_upload BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_access_links DROP can_upload');
    }
}
