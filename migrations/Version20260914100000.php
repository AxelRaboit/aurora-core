<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Whether a form's own page asks to be indexed.
 *
 * `/{locale}/forms/{slug}` carries the same questions as the content the form
 * was posed in, under a second address. Nothing links to it and it is out of
 * the sitemap, so today the duplicate is theoretical - it stops being so the
 * day a menu entry points at it.
 *
 * False for everyone, including the forms already in place. It is the answer
 * that matches what those sites actually do with the page: nothing. A form
 * that wants its own page found says so.
 */
final class Version20260914100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A form says whether its own page asks to be indexed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_forms ADD standalone_page_indexed BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_forms DROP standalone_page_indexed');
    }
}
