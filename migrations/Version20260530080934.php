<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

use function is_array;
use function is_int;
use function is_string;

/**
 * Phase 3 of the Media → GED merge - remap the JSONB-embedded `mediaId`
 * references in EditorJS blocks (`core_post_translations.blocks` and
 * `core_block_notes.blocks`) to point at Document IDs instead.
 *
 * Strategy:
 *   1. Build the media_id → document_id mapping once, via the unique
 *      file_path bridge populated by the earlier Phase 2 migrations.
 *   2. For each row whose blocks payload references a known media ID,
 *      rewrite `data.mediaId` in place and persist the new payload.
 *
 * Rows whose blocks reference Media that have NO corresponding Document
 * (the source `core_media` row had not been copied because no Phase 2
 * consumer referenced it) get the *source media row* copied on the fly
 * to keep their content rendering. Forward-only; the original Media
 * library survives until Phase 5.
 *
 * Touches every `image` and `mediaText` block since both expose a
 * `data.mediaId` integer.
 */
final class Version20260530080934 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 3: JSONB content blocks - remap embedded mediaId → Document id';
    }

    public function up(Schema $schema): void
    {
        $this->ensureContentMediaRowsAreInDocuments();
        $mapping = $this->mediaToDocumentMapping();
        if ([] === $mapping) {
            return;
        }

        $this->remapBlocks('core_post_translations', 'id', 'blocks', $mapping);
        $this->remapBlocks('core_block_notes', 'id', 'blocks', $mapping);
    }

    public function down(Schema $schema): void
    {
        // Forward-only - content migration is not safely reversible. The
        // original `core_media` rows are still intact until Phase 5 drops
        // them, so a manual roll-back script is possible if needed.
    }

    /**
     * Copies any `core_media` row referenced by an `image`/`mediaText`
     * block into `core_ged_documents` if not already there. Keeps the
     * NOT EXISTS guard so re-runs are idempotent and rows already
     * migrated by Phase 2 consumers are not duplicated.
     */
    private function ensureContentMediaRowsAreInDocuments(): void
    {
        $this->addSql(<<<'SQL'
                INSERT INTO core_ged_documents (
                    id,
                    title, description, status,
                    file_path, file_name, original_name, mime_type, size,
                    width, height, thumbnail_path,
                    alt, caption,
                    focal_x, focal_y, variants,
                    created_at, updated_at
                )
                SELECT
                    nextval('seq_core_ged_document_id'),
                    COALESCE(NULLIF(m.original_name, ''), 'Untitled') AS title,
                    NULL AS description,
                    'published' AS status,
                    m.path AS file_path,
                    m.filename AS file_name,
                    m.original_name,
                    m.mime_type,
                    m.size,
                    m.width,
                    m.height,
                    NULL AS thumbnail_path,
                    m.alt,
                    m.caption,
                    m.focal_x,
                    m.focal_y,
                    m.variants,
                    m.created_at,
                    m.updated_at
                FROM core_media m
                WHERE NOT EXISTS (SELECT 1 FROM core_ged_documents d WHERE d.file_path = m.path)
                  AND (
                      EXISTS (
                          SELECT 1 FROM core_post_translations t,
                              jsonb_array_elements(t.blocks::jsonb) AS b
                          WHERE COALESCE(NULLIF((b->'data'->>'mediaId'), '')::bigint, 0) = m.id
                      )
                      OR EXISTS (
                          SELECT 1 FROM core_block_notes n,
                              jsonb_array_elements(n.blocks::jsonb) AS b
                          WHERE COALESCE(NULLIF((b->'data'->>'mediaId'), '')::bigint, 0) = m.id
                      )
                  )
            SQL);
    }

    /** @return array<int, int> media_id → document_id */
    private function mediaToDocumentMapping(): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT m.id AS media_id, d.id AS document_id
                FROM core_media m
                INNER JOIN core_ged_documents d ON d.file_path = m.path
            SQL);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['media_id']] = (int) $row['document_id'];
        }

        return $map;
    }

    /** @param array<int, int> $mapping */
    private function remapBlocks(string $table, string $pkColumn, string $blocksColumn, array $mapping): void
    {
        $rows = $this->connection->fetchAllAssociative(sprintf(
            'SELECT %s, %s FROM %s WHERE %s IS NOT NULL',
            $pkColumn,
            $blocksColumn,
            $table,
            $blocksColumn,
        ));

        foreach ($rows as $row) {
            $pk = $row[$pkColumn];
            $rawBlocks = $row[$blocksColumn];
            $decoded = is_string($rawBlocks) ? json_decode($rawBlocks, true) : $rawBlocks;
            if (!is_array($decoded) || [] === $decoded) {
                continue;
            }

            [$updated, $dirty] = $this->rewriteBlockArray($decoded, $mapping);
            if (!$dirty) {
                continue;
            }

            $this->connection->executeStatement(
                sprintf('UPDATE %s SET %s = :blocks WHERE %s = :pk', $table, $blocksColumn, $pkColumn),
                ['blocks' => json_encode($updated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'pk' => $pk],
            );
        }
    }

    /**
     * @param array<int|string, mixed> $blocks
     * @param array<int, int>          $mapping
     *
     * @return array{0: array<int|string, mixed>, 1: bool}
     */
    private function rewriteBlockArray(array $blocks, array $mapping): array
    {
        $dirty = false;
        foreach ($blocks as $idx => $block) {
            if (!is_array($block)) {
                continue;
            }
            if (!isset($block['data']) || !is_array($block['data'])) {
                continue;
            }
            $current = $block['data']['mediaId'] ?? null;
            if (!is_int($current) && !(is_string($current) && '' !== $current && ctype_digit($current))) {
                continue;
            }

            $mediaId = (int) $current;
            $newId = $mapping[$mediaId] ?? null;
            if (null === $newId) {
                continue;
            }

            $block['data']['mediaId'] = $newId;
            $blocks[$idx] = $block;
            $dirty = true;
        }

        return [$blocks, $dirty];
    }
}
