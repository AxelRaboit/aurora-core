<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Aurora\Module\Editorial\Post\Gallery\GalleryNormalizer;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds a post's image gallery: the arrangement, and the words for it.
 *
 * Two columns, split the way the banner and the content grid already are. The
 * arrangement goes on the post, because a gallery is designed once and every
 * language shows the same pictures in the same order; the alt text and captions
 * go on each translation, keyed by the item id the arrangement settles. See
 * {@see GalleryNormalizer} for the shape of both.
 *
 * `[]` on both, which is what "never configured" means throughout this module:
 * no post changes appearance on deploy, and the gallery only exists on posts
 * whose author turns it on.
 *
 * Written by hand, as with every migration here: `doctrine:migrations:diff`
 * compares the dev database against the entities currently mapped, and every
 * module absent from this checkout reads as a table to drop.
 */
final class Version20260823100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the post gallery: core_posts.gallery_layout and core_post_translations.gallery';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE core_posts ADD gallery_layout JSON DEFAULT '[]' NOT NULL");
        $this->addSql("ALTER TABLE core_post_translations ADD gallery JSON DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core_posts DROP gallery_layout');
        $this->addSql('ALTER TABLE core_post_translations DROP gallery');
    }
}
