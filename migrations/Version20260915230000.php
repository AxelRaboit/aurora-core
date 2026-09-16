<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What a client answered about a piece of content, and who may answer.
 *
 * Written by hand, like the ones before it: `migrations:diff` on this
 * development database proposes dropping every table the removed modules left.
 *
 * `can_approve` defaults to true, including on the links already issued. That
 * is what they were created for - a studio sends a link to get an answer - and
 * defaulting to false would have silently taken the right away from addresses
 * somebody has already mailed out.
 *
 * `approval_by_link_id` is `SET NULL`: deleting an address must not delete the
 * answer it carried. What is lost is who, not what.
 */
final class Version20260915230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A client answers on their content, and a link says whether they may';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_access_links ADD can_approve BOOLEAN DEFAULT true NOT NULL');

        $this->addSql('ALTER TABLE core_studio_space_content_items ADD approval VARCHAR(20) DEFAULT \'pending\' NOT NULL');
        $this->addSql('ALTER TABLE core_studio_space_content_items ADD approval_note TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE core_studio_space_content_items ADD approval_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE core_studio_space_content_items ADD approval_by_link_id INT DEFAULT NULL');

        $this->addSql('ALTER TABLE core_studio_space_content_items ADD CONSTRAINT FK_3C4C0333B7C0F7D1 FOREIGN KEY (approval_by_link_id) REFERENCES core_studio_space_access_links (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_3C4C0333B7C0F7D1 ON core_studio_space_content_items (approval_by_link_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_studio_space_content_items DROP CONSTRAINT FK_3C4C0333B7C0F7D1');
        $this->addSql('DROP INDEX IDX_3C4C0333B7C0F7D1');

        $this->addSql('ALTER TABLE core_studio_space_content_items DROP approval_by_link_id');
        $this->addSql('ALTER TABLE core_studio_space_content_items DROP approval_at');
        $this->addSql('ALTER TABLE core_studio_space_content_items DROP approval_note');
        $this->addSql('ALTER TABLE core_studio_space_content_items DROP approval');

        $this->addSql('ALTER TABLE core_studio_space_access_links DROP can_approve');
    }
}
