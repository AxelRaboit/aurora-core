<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Gallery;

/**
 * The one shape a post's gallery is ever stored in.
 *
 * Split the same way the banner and the content grid are, and for the same
 * reason: the **arrangement** lives once on the post, the **words** live on each
 * translation, joined back by item id. A gallery designed in French is the same
 * gallery in English; only its alt text and captions are translated.
 *
 * Every write goes through here. Nothing else decides a default, so a gallery
 * saved by an older editor, by a fixture, or by a client project reads back with
 * the same keys as one saved today.
 *
 * **Why a gallery at all, next to a media zone in the grid.** A grid zone holds
 * one picture and is placed by hand. A post that is mostly photographs would
 * mean laying out twenty zones, and every new photograph would mean laying out
 * one more. A gallery is the case where the arrangement is a rule rather than a
 * decision: n columns, one ratio, in order.
 */
final class GalleryNormalizer
{
    /** Uniform tiles: every image cropped to the same ratio, read left to right. */
    public const string LAYOUT_GRID = 'grid';

    /**
     * Natural proportions, columns filled independently.
     *
     * Reads **down a column**, not across a row, which is what CSS columns do
     * and what no amount of styling changes. Worth knowing when the order of the
     * pictures carries something: a diptych meant to be seen side by side can
     * land one above the other.
     */
    public const string LAYOUT_MASONRY = 'masonry';

    public const array LAYOUTS = [self::LAYOUT_GRID, self::LAYOUT_MASONRY];

    /**
     * Ratios, borrowed verbatim from the grid's media zone.
     *
     * Not a second list of the same thing: an author who has learnt "4/3" in a
     * content zone should not meet a different set of words here. `fill` is
     * absent on purpose - it means "take the height the stack has left", and a
     * gallery has no stack.
     */
    public const string RATIO_NATURAL = 'natural';

    public const array RATIOS = [self::RATIO_NATURAL, '16x9', '4x3', '1x1', '3x4'];

    /**
     * Columns, at the large breakpoint. Narrow screens step down on their own in
     * the template rather than by a second setting: a column count that only
     * makes sense on a phone is not a decision an author wants to make.
     */
    public const array COLUMNS = [2, 3, 4, 5];

    public const int DEFAULT_COLUMNS = 3;

    /**
     * A ceiling, not a guess.
     *
     * A gallery is a page's whole content in this design, so the limit is about
     * what a reader will scroll and what a browser will decode at once, not
     * about the editor. Sixty full-size images is already several megabytes
     * before any thumbnailing.
     */
    public const int MAX_ITEMS = 60;

    /**
     * The arrangement, shared by every language.
     *
     * @return array<string, mixed>
     */
    public function normalizeLayout(mixed $raw): array
    {
        $data = is_array($raw) ? $raw : [];

        return [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'layout' => $this->oneOf($data['layout'] ?? null, self::LAYOUTS, self::LAYOUT_GRID),
            'columns' => $this->oneOfInt($data['columns'] ?? null, self::COLUMNS, self::DEFAULT_COLUMNS),
            'ratio' => $this->oneOf($data['ratio'] ?? null, self::RATIOS, self::RATIO_NATURAL),
            'items' => $this->items($data),
        ];
    }

    /**
     * The words, for one language, keyed by item id under `items`.
     *
     * Takes the layout because that is what says which items exist: an image the
     * author removed must not keep its caption in five languages.
     *
     * **Symmetric, since it was not.** It read `items` and returned the bare map,
     * so what it wrote could not be read back: the editor sent
     * `{items: {g1: {alt: 'x'}}}`, the column stored `{g1: {alt: 'x'}}`, and the
     * next pass through here found no `items` key and emptied every caption. The
     * author saw their alt text vanish on reload, and the save after that wrote the
     * blanks. {@see GridNormalizer::normalizeContent()} had it right all along -
     * `['zones' => …]` in and out.
     *
     * The input still accepts the bare map, which is what recovers the rows written
     * while it was broken. Absent `items` on a payload that *is* the map is not
     * ambiguous: item ids are strings and `items` is not one of them.
     *
     * @param array<string, mixed> $layout an already-normalised layout
     *
     * @return array<string, mixed>
     */
    public function normalizeContent(mixed $raw, array $layout): array
    {
        $data = is_array($raw) ? $raw : [];
        $stored = is_array($data['items'] ?? null) ? $data['items'] : $data;

        // Read defensively: no gallery at all is a legitimate argument, and
        // reaching for a missing key would turn it into a crash.
        $items = is_array($layout['items'] ?? null) ? $layout['items'] : [];

        $content = [];
        foreach ($items as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;
            if (!is_string($id)) {
                continue;
            }

            $entry = is_array($stored[$id] ?? null) ? $stored[$id] : [];

            $content[$id] = [
                'alt' => $this->text($entry['alt'] ?? null),
                'caption' => $this->text($entry['caption'] ?? null),
            ];
        }

        return ['items' => $content];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function items(array $data): array
    {
        $raw = is_array($data['items'] ?? null) ? $data['items'] : [];

        $items = [];
        $seen = [];
        foreach ($raw as $item) {
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }

            if (!is_array($item)) {
                continue;
            }

            $id = $item['id'] ?? null;
            $mediaId = $item['mediaId'] ?? null;
            // An item with no picture is not a gap to keep: the whole item is
            // the picture, unlike a grid zone which exists before it is filled.
            if (!is_string($id)) {
                continue;
            }

            if ('' === $id) {
                continue;
            }

            if (!is_numeric($mediaId)) {
                continue;
            }

            // A picture repeated in one gallery is a mistake every time, and one
            // the author cannot see when the page is long: keep the first.
            if (isset($seen[(int) $mediaId])) {
                continue;
            }

            $seen[(int) $mediaId] = true;
            $items[] = ['id' => $id, 'mediaId' => (int) $mediaId];
        }

        return $items;
    }

    /**
     * @param list<string> $allowed
     */
    private function oneOf(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * @param list<int> $allowed
     */
    private function oneOfInt(mixed $value, array $allowed, int $default): int
    {
        return is_numeric($value) && in_array((int) $value, $allowed, true) ? (int) $value : $default;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? mb_trim($value) : '';
    }
}
