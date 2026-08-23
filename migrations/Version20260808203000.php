<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Splits the banner in two: the design moves to the post, the words stay on
 * each translation.
 *
 * The banner was stored whole, per translation. Translating one meant
 * rebuilding its layout by hand in every language, and nothing said which of
 * two divergent copies was the intended design. Now one post carries one
 * layout and each translation carries only its copy, joined back by item id.
 *
 * Written by hand rather than generated, for the same reason as
 * {@see Version20260808160504}: `migrations:diff` still wants to drop every
 * table belonging to the extracted modules, so it buries the one statement
 * that matters under hundreds that must not run.
 *
 * The data move runs in `postUp`, after the column exists - statements queued
 * with `addSql` have not been executed yet while `up()` is still running.
 */
final class Version20260808203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move the banner layout from post translations to the post itself';
    }

    public function up(Schema $schema): void
    {
        // DEFAULT is required, not cosmetic: the table already has rows, and
        // PostgreSQL refuses to add a NOT NULL column to them without one.
        $this->addSql("ALTER TABLE core_posts ADD banner_layout JSON DEFAULT '[]' NOT NULL");
    }

    public function postUp(Schema $schema): void
    {
        /** @var list<array{id: int}> $posts */
        $posts = $this->connection->fetchAllAssociative('SELECT id FROM core_posts');

        foreach ($posts as $post) {
            $this->splitPost((int) $post['id']);
        }
    }

    public function down(Schema $schema): void
    {
        // Deliberately one-way for the data. Rebuilding a per-translation
        // banner would mean copying the shared layout into every locale, which
        // recreates by hand exactly the duplication this migration removes -
        // and a later `up` could not tell the copies from edits. The column
        // goes; the texts stay where they are, which is where they were.
        $this->addSql('ALTER TABLE core_posts DROP banner_layout');
    }

    /**
     * Takes the layout from the translation that has one, and rewrites every
     * translation of the post to hold only its own words.
     */
    private function splitPost(int $postId): void
    {
        /** @var list<array{id: int, locale: string, banner: ?string}> $translations */
        $translations = $this->connection->fetchAllAssociative(
            'SELECT id, locale, banner FROM core_post_translations WHERE post_id = :post ORDER BY locale',
            ['post' => $postId],
        );

        $banners = [];
        foreach ($translations as $translation) {
            $decoded = json_decode((string) $translation['banner'], true);
            $banners[(int) $translation['id']] = is_array($decoded) ? $decoded : [];
        }

        $source = $this->pickLayoutSource($banners);

        if (null === $source) {
            // No banner anywhere on this post. The column default already says
            // so; rewriting the translations would only churn rows.
            return;
        }

        $items = is_array($source['items'] ?? null) ? array_values($source['items']) : [];

        // Ids are assigned here and are what every language's text will hang
        // off from now on. Positional, because the old shape was: the item at
        // index 2 in French is the item at index 2 in German.
        $ids = [];
        foreach (array_keys($items) as $index) {
            $ids[$index] = 'i'.($index + 1);
        }

        $this->connection->update(
            'core_posts',
            ['banner_layout' => json_encode($this->layout($source, $items, $ids), JSON_THROW_ON_ERROR)],
            ['id' => $postId],
        );

        foreach ($banners as $translationId => $banner) {
            $this->connection->update(
                'core_post_translations',
                ['banner' => json_encode($this->texts($banner, $ids), JSON_THROW_ON_ERROR)],
                ['id' => $translationId],
            );
        }
    }

    /**
     * The language that actually holds a design. An enabled banner wins over a
     * merely non-empty one, and among equals the first locale alphabetically -
     * arbitrary, but stable, so re-running on a copy of the database gives the
     * same answer.
     *
     * @param array<int, array<string, mixed>> $banners
     *
     * @return array<string, mixed>|null
     */
    private function pickLayoutSource(array $banners): ?array
    {
        $fallback = null;

        foreach ($banners as $banner) {
            if ([] === $banner) {
                continue;
            }

            if (true === ($banner['enabled'] ?? false)) {
                return $banner;
            }

            $fallback ??= $banner;
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $source
     * @param list<mixed>          $items
     * @param array<int, string>   $ids
     *
     * @return array<string, mixed>
     */
    private function layout(array $source, array $items, array $ids): array
    {
        $layoutItems = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $layoutItems[] = [
                'id' => $ids[$index],
                'type' => $item['type'] ?? null,
                'span' => $item['span'] ?? [],
                'titleColor' => $item['titleColor'] ?? null,
                'descriptionColor' => $item['descriptionColor'] ?? null,
                'align' => $item['align'] ?? null,
                'titleSize' => $item['titleSize'] ?? null,
                'mediaId' => $item['mediaId'] ?? null,
                'buttonColor' => $item['buttonColor'] ?? null,
                'buttonTextColor' => $item['buttonTextColor'] ?? null,
            ];
        }

        return [
            'enabled' => $source['enabled'] ?? false,
            'height' => $source['height'] ?? null,
            'width' => $source['width'] ?? null,
            'verticalAlign' => $source['verticalAlign'] ?? null,
            'logoMediaId' => $source['logoMediaId'] ?? null,
            'background' => $source['background'] ?? [],
            'items' => $layoutItems,
        ];
    }

    /**
     * A translation keeps its own words, matched to the shared layout by
     * position. A language that had fewer items than the source simply has
     * nothing to say about the last ones, which is what an empty string means
     * here; one that had more loses the surplus, because the layout it would
     * have hung off does not exist.
     *
     * @param array<string, mixed> $banner
     * @param array<int, string>   $ids
     *
     * @return array<string, mixed>
     */
    private function texts(array $banner, array $ids): array
    {
        $items = is_array($banner['items'] ?? null) ? array_values($banner['items']) : [];
        $texts = [];

        foreach ($ids as $index => $id) {
            $item = is_array($items[$index] ?? null) ? $items[$index] : [];

            $texts[$id] = [
                'title' => $item['title'] ?? '',
                'description' => $item['description'] ?? '',
                'alt' => $item['alt'] ?? '',
                'label' => $item['label'] ?? '',
                'url' => $item['url'] ?? null,
            ];
        }

        return ['items' => $texts];
    }
}
