<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Aurora\Module\Editorial\Post\Banner\BannerNormalizer;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Moves every banner saved as full-bleed onto the aligned full width.
 *
 * The `full` placement is retired: background and content both went to the
 * window edge, so a banner's title started nowhere near the text under it — at
 * 1920px, 267 pixels apart. See {@see BannerNormalizer::WIDTH_FULL_RETIRED}
 * for why an option that reads as a mistake was worth removing rather than
 * documenting.
 *
 * **Rewritten rather than left to fall back.** `oneOf` answers the default for
 * a value it no longer knows, and the default is `contained` — so doing nothing
 * would have moved every one of these banners from full width into the article
 * column. That is a larger change than the one being made, and it would have
 * happened silently, on pages nobody edited. `full_aligned` is the nearest
 * neighbour: same full-width background, same height, only the text moving into
 * line with the column beneath it.
 *
 * Touches the two places a banner width is kept — the post's own arrangement, and
 * the `snapshot` a revision holds the whole post in — and only rows that
 * actually say `full`, so re-running it is a no-op.
 *
 * Written by hand, as with every migration in this module: `make:migration`
 * compares the dev database against the entities currently mapped, and every
 * extracted module absent from this checkout reads as a table to drop.
 */
final class Version20260809210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move banners saved as full-bleed onto the aligned full width';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE core_posts
            SET banner_layout = jsonb_set(banner_layout::jsonb, '{width}', '"full_aligned"')::json
            WHERE banner_layout::jsonb ->> 'width' = 'full'
            SQL);

        // A revision keeps the whole post in one `snapshot`, not in columns of
        // its own — so the width sits a level down, under `bannerLayout`.
        // Missed on the first pass, and caught by running the migration rather
        // than by reading it: the column this used to name does not exist.
        $this->addSql(<<<'SQL'
            UPDATE core_post_revisions
            SET snapshot = jsonb_set(snapshot::jsonb, '{bannerLayout,width}', '"full_aligned"')::json
            WHERE snapshot::jsonb -> 'bannerLayout' ->> 'width' = 'full'
            SQL);
    }

    /**
     * Deliberately empty.
     *
     * Putting `full` back would mean guessing which of the rows now saying
     * `full_aligned` had been full-bleed before, and there is nothing left in
     * the row to tell them apart. Reversing this by re-reading the two values
     * as one is the honest answer: nothing was lost that a re-choice cannot
     * restore, and inventing the distinction back would be worse than leaving
     * it.
     */
    public function down(Schema $schema): void {}
}
