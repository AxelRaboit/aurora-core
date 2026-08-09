<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Every publication's body becomes a content grid.
 *
 * The grid and the plain block column have cohabited since the grid shipped:
 * the editor hid one when the other was on, and the template picked between
 * them. Two systems doing one job is a cost paid on every editorial change
 * afterwards, and the grid is a strict superset — a single full-width text zone
 * *is* the plain column.
 *
 * **Measured before deciding, not assumed.** A post rendered both ways puts its
 * paragraph, its h2 and its list at the same left, the same width and the same
 * pixel from the top; the inner HTML is byte-identical and only three wrappers
 * differ. `prose` resets the first child's margin and the wrapper supplies the
 * space the block path got from the margin itself, so the two agree. Nothing
 * moves on a published page.
 *
 * **`blocks` is deliberately not dropped.** The column keeps every value it
 * had, which is what makes this reversible: `down()` only has to switch the
 * grid off again, because the words never left. Dropping it is a later
 * decision, taken once nothing has read it for a while — not the same day the
 * data moves.
 *
 * Only posts that have no grid are touched. The two that already had one keep
 * it, and their stale `blocks` are left alone for the same reason.
 *
 * Written by hand, as with every migration in this module: `make:migration`
 * compares the dev database against the entities currently mapped, and every
 * extracted module absent from this checkout reads as a table to drop.
 */
final class Version20260809150000 extends AbstractMigration
{
    /**
     * Readable rather than random, because a human will meet it in a JSON
     * column one day. `ContentValueNormalizer::ITEM_ID` accepts it, and ids
     * only have to be unique inside one post.
     */
    private const string ZONE_ID = 'body';

    public function getDescription(): string
    {
        return 'Move every plain block column into a one-zone content grid';
    }

    public function up(Schema $schema): void
    {
        // The arrangement, on the post: one text zone, full width at every
        // breakpoint — which is exactly what the plain column was.
        $this->addSql(<<<'SQL'
            UPDATE core_posts SET grid_layout = json_build_object(
                'enabled', true,
                'snap', 4,
                'zones', json_build_array(json_build_object(
                    'id', 'body',
                    'type', 'text',
                    'span', json_build_object('base', 48, 'md', null, 'lg', 48),
                    'ratio', 'natural',
                    'mediaId', null,
                    'postId', null,
                    'children', json_build_array()
                ))
            )
            WHERE coalesce(grid_layout::jsonb->>'enabled', 'false') <> 'true'
              AND EXISTS (
                  SELECT 1 FROM core_post_translations t
                  WHERE t.post_id = core_posts.id
                    AND jsonb_array_length(t.blocks::jsonb) > 0
              )
            SQL);

        // The words, on each translation, under the id the arrangement names.
        // Every language of the post gets an entry, including one whose blocks
        // are empty: an absent entry and an empty one mean the same thing to
        // the normaliser, and writing both keeps the two halves in step.
        $this->addSql(<<<'SQL'
            UPDATE core_post_translations t SET grid = json_build_object(
                'zones', json_build_object('body', json_build_object(
                    'blocks', t.blocks::jsonb,
                    'alt', '',
                    'caption', '',
                    'url', null
                ))
            )
            FROM core_posts p
            WHERE p.id = t.post_id
              AND p.grid_layout::jsonb->'zones'->0->>'id' = 'body'
            SQL);
    }

    /**
     * Switching the grid back off is enough to restore the previous rendering,
     * because `blocks` still holds every word. The zone's copy is cleared too,
     * so a second `up()` would not read a half-migrated state.
     */
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE core_post_translations t SET grid = '{"zones": {}}'
            FROM core_posts p
            WHERE p.id = t.post_id
              AND p.grid_layout::jsonb->'zones'->0->>'id' = 'body'
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE core_posts SET grid_layout = json_build_object(
                'enabled', false, 'snap', 4, 'zones', json_build_array()
            )
            WHERE grid_layout::jsonb->'zones'->0->>'id' = 'body'
            SQL);
    }
}
