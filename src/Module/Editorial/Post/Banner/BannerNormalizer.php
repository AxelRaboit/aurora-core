<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Banner;

use Aurora\Core\Content\ContentValueNormalizer;

/**
 * Normalises the banner, which is stored in two halves.
 *
 * The **layout** lives on the post and is shared by every language: the
 * arrangement, the widths, the colours, the pictures. The **texts** live on
 * each translation: the words, their alt text, and the link a button points
 * at. Writing the banner once and translating only the copy is the whole
 * point of the split — the previous shape kept everything per translation, so
 * a second language meant rebuilding the design by hand, and the two could
 * drift apart with nothing to say which was right.
 *
 * The two halves are joined by a stable item **id**. Not by position: moving
 * an item in the layout would otherwise re-point every other language's text
 * at the wrong item, silently. An id survives reordering, insertion and
 * removal.
 *
 * A link is text, not layout, and the data says so — the first banner written
 * against this module stored `/fr/page/premiers-pas`. A localised page has a
 * localised address.
 *
 * Every value that reaches the database goes through here, and anything not
 * explicitly allowed is dropped. That is a deliberate contrast with `blocks`,
 * which is written raw and only sanitised at render time — the reason two
 * block types have been shipping a shape the renderer cannot read without
 * anyone noticing. A banner that survives a round-trip is a banner the
 * renderer can trust.
 *
 * The width lives on a 48-column grid, and 48 is not arbitrary: it is 4 × 12
 * and 2 × 24, so a twelfth is 4 columns and a twenty-fourth is 2. Halves,
 * thirds, quarters, sixths and eighths all land on whole numbers. The same
 * grid carries the content layout, which is why the shape lives here rather
 * than being invented twice.
 *
 * @phpstan-type BannerSpan array{base: int, md: ?int, lg: ?int}
 * @phpstan-type BannerLayoutItem array{id: string, type: string, span: BannerSpan, titleColor: ?string, descriptionColor: ?string, align: string, titleSize: string, mediaId: ?int, buttonColor: ?string, buttonTextColor: ?string}
 * @phpstan-type BannerItemText array{title: string, description: string, alt: string, label: string, url: ?string}
 */
final readonly class BannerNormalizer
{
    public const string ITEM_TEXT = 'text';

    public const string ITEM_IMAGE = 'image';

    public const string ITEM_BUTTON = 'button';

    public const string WIDTH_CONTAINED = 'contained';

    public const string WIDTH_FULL = 'full';

    /**
     * Background spans the viewport, content keeps the page's own left edge.
     * Usually what "full width" is meant to look like: at 1920px the two
     * differ by 267 pixels, and a title that starts nowhere near the text
     * under it reads as a mistake rather than a choice.
     */
    public const string WIDTH_FULL_ALIGNED = 'full_aligned';

    public const string FILL_NONE = 'none';

    public const string FILL_SOLID = 'solid';

    public const string FILL_GRADIENT = 'gradient';

    /** Full width of the grid, and the default span of a new item. */
    public const int COLUMNS = ContentValueNormalizer::COLUMNS;

    /**
     * A banner is a header, not a page. The cap is here so a runaway payload
     * cannot turn one into an unbounded list — the content grid is where many
     * items belong.
     */
    private const int MAX_ITEMS = 6;

    private const array ITEM_TYPES = [self::ITEM_TEXT, self::ITEM_IMAGE, self::ITEM_BUTTON];

    private const array TITLE_SIZES = ['sm', 'md', 'lg', 'xl'];

    private const array VERTICAL_ALIGNMENTS = ['start', 'center', 'end'];

    private const array FILL_TYPES = [self::FILL_NONE, self::FILL_SOLID, self::FILL_GRADIENT];

    private const array HEIGHTS = ['sm', 'md', 'lg', 'full'];

    private const array WIDTHS = [self::WIDTH_CONTAINED, self::WIDTH_FULL_ALIGNED, self::WIDTH_FULL];

    private const array ALIGNMENTS = ['start', 'center', 'end'];

    public function __construct(
        private ContentValueNormalizer $values,
    ) {}

    /**
     * The design, shared by every language.
     *
     * @param mixed $raw whatever the client sent
     *
     * @return array<string, mixed> a layout that is safe to persist and render
     */
    public function normalizeLayout(mixed $raw): array
    {
        $data = is_array($raw) ? $raw : [];

        return [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'height' => $this->values->oneOf($data['height'] ?? null, self::HEIGHTS, 'md'),
            // Where the banner sits: inside the article column like the rest of
            // the page, or spanning the viewport flush under the top bar.
            'width' => $this->values->oneOf($data['width'] ?? null, self::WIDTHS, self::WIDTH_CONTAINED),
            // Where the content sits in a banner taller than it needs: pinned
            // to the top, centred, or dropped to the bottom.
            'verticalAlign' => $this->values->oneOf($data['verticalAlign'] ?? null, self::VERTICAL_ALIGNMENTS, 'center'),
            'logoMediaId' => $this->values->id($data['logoMediaId'] ?? null),
            'background' => $this->background(is_array($data['background'] ?? null) ? $data['background'] : []),
            'items' => $this->layoutItems($data),
        ];
    }

    /**
     * The words, for one language.
     *
     * Takes the layout because that is what says which items exist. Text for
     * an item that is gone is dropped rather than kept "just in case": a
     * translation that quietly accumulates orphans is a translation nobody can
     * reason about, and the editor would have no way to show or clear them.
     *
     * @param mixed                $raw    whatever the client sent
     * @param array<string, mixed> $layout an already-normalised layout
     *
     * @return array<string, mixed> texts keyed by item id
     */
    public function normalizeTexts(mixed $raw, array $layout): array
    {
        $data = is_array($raw) ? $raw : [];

        // Read defensively rather than trusting the caller to have normalised:
        // an empty layout is a legitimate argument — a post with no banner —
        // and reaching for a key that is not there would turn it into a crash.
        $items = is_array($layout['items'] ?? null) ? $layout['items'] : [];
        $ids = array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_array($item) && is_string($item['id'] ?? null) ? $item['id'] : null,
            $items,
        )));

        $stored = is_array($data['items'] ?? null) ? $data['items'] : [];

        // A banner written before the split holds one flat list carrying both
        // halves. Its texts are positional, and the layout was built from that
        // very list, so position is exactly how they line up. Converted here
        // rather than only in the migration so a client database restored from
        // an older dump reads correctly instead of losing its copy.
        if ($this->isLegacyShape($stored)) {
            $stored = $this->textsFromLegacyItems($stored, $ids);
        }

        $texts = [];

        foreach ($ids as $id) {
            $entry = is_array($stored[$id] ?? null) ? $stored[$id] : [];

            $texts[$id] = [
                'title' => $this->values->text($entry['title'] ?? null),
                'description' => $this->values->text($entry['description'] ?? null),
                'alt' => $this->values->text($entry['alt'] ?? null),
                'label' => $this->values->text($entry['label'] ?? null),
                'url' => $this->values->url($entry['url'] ?? null),
            ];
        }

        return ['items' => $texts];
    }

    /** An empty layout — what a post starts life with. */
    public function emptyLayout(): array
    {
        return $this->normalizeLayout([]);
    }

    /** Empty texts — what a translation starts life with. */
    public function emptyTexts(): array
    {
        return ['items' => []];
    }

    /** @param array<string, mixed> $data */
    private function background(array $data): array
    {
        return [
            // Explicit rather than inferred from which colours are filled:
            // going back from a gradient to a flat colour then means picking
            // "solid", not clearing two fields and hoping.
            //
            // The one exception is a banner written before this field existed:
            // it has a colour and no type, and reading it as "no fill" would
            // silently strip a background someone chose. Absent *and* coloured
            // upgrades to solid; present but unknown still falls back to none.
            'type' => $this->fillType($data),
            'color' => $this->values->color($data['color'] ?? null),
            'gradientFrom' => $this->values->color($data['gradientFrom'] ?? null),
            'gradientTo' => $this->values->color($data['gradientTo'] ?? null),
            // Degrees, the CSS sense: 0 points up, 180 down.
            'gradientAngle' => max(0, min(360, (int) ($data['gradientAngle'] ?? 180))),
            'mediaId' => $this->values->id($data['mediaId'] ?? null),
            // Percentage, so a background image can be darkened enough for
            // text to stay readable over it.
            'overlay' => max(0, min(100, (int) ($data['overlay'] ?? 0))),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function layoutItems(array $data): array
    {
        $raw = is_array($data['items'] ?? null)
            ? $data['items']
            : $this->itemsFromLegacySlots($data);

        $items = [];
        $used = [];

        foreach (array_slice(array_values($raw), 0, self::MAX_ITEMS) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            // An unknown type drops the item rather than defaulting to text:
            // a banner is better short one element than showing an empty box
            // where something else was meant to be.
            $type = $this->values->oneOf($entry['type'] ?? null, self::ITEM_TYPES, '');
            if ('' === $type) {
                continue;
            }

            $id = $this->values->itemId($entry['id'] ?? null, $used);
            $used[$id] = true;

            // Every key is present whatever the type. Switching an item from
            // text to image and back in the editor would otherwise lose what
            // was set, and the front would have to guard every read.
            $items[] = [
                'id' => $id,
                'type' => $type,
                'span' => $this->values->span($entry['span'] ?? null),
                'titleColor' => $this->values->color($entry['titleColor'] ?? null),
                'descriptionColor' => $this->values->color($entry['descriptionColor'] ?? null),
                'align' => $this->values->oneOf($entry['align'] ?? null, self::ALIGNMENTS, 'start'),
                'titleSize' => $this->values->oneOf($entry['titleSize'] ?? null, self::TITLE_SIZES, 'md'),
                'mediaId' => $this->values->id($entry['mediaId'] ?? null),
                'buttonColor' => $this->values->color($entry['buttonColor'] ?? null),
                'buttonTextColor' => $this->values->color($entry['buttonTextColor'] ?? null),
            ];
        }

        return $items;
    }

    /**
     * True when the list carries item *definitions* rather than texts keyed by
     * id — the pre-split shape. A `type` key is what distinguishes them: texts
     * have never had one.
     *
     * @param array<mixed> $stored
     */
    private function isLegacyShape(array $stored): bool
    {
        if (!array_is_list($stored)) {
            return false;
        }

        return array_any($stored, fn ($entry): bool => is_array($entry) && isset($entry['type']));
    }

    /**
     * @param array<mixed> $stored
     * @param list<string> $ids
     *
     * @return array<string, mixed>
     */
    private function textsFromLegacyItems(array $stored, array $ids): array
    {
        $texts = [];

        foreach (array_values($stored) as $index => $entry) {
            if (is_array($entry) && isset($ids[$index])) {
                $texts[$ids[$index]] = $entry;
            }
        }

        return $texts;
    }

    /**
     * Banners written against the first shape carry two fixed `slots` and a
     * `ratio`. Converting them here rather than in a migration keeps the
     * upgrade in one place and costs nothing once no such row is left: the
     * branch is only reached when `items` is absent entirely.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function itemsFromLegacySlots(array $data): array
    {
        if (!is_array($data['slots'] ?? null)) {
            return [];
        }

        $filled = array_values(array_filter(
            $data['slots'],
            static fn (mixed $slot): bool => is_array($slot) && in_array($slot['type'] ?? null, self::ITEM_TYPES, true),
        ));

        // The old ratio applied only when both slots held something; a lone
        // slot spanned the row.
        $spans = match (2 === count($filled) ? ($data['ratio'] ?? '50-50') : null) {
            '33-67' => [16, 32],
            '67-33' => [32, 16],
            '50-50' => [24, 24],
            default => [self::COLUMNS],
        };

        return array_map(
            static fn (array $slot, int $index): array => [...$slot, 'span' => ['lg' => $spans[$index] ?? self::COLUMNS]],
            $filled,
            array_keys($filled),
        );
    }

    /** @param array<string, mixed> $data */
    private function fillType(array $data): string
    {
        if (!array_key_exists('type', $data) && null !== $this->values->color($data['color'] ?? null)) {
            return self::FILL_SOLID;
        }

        return $this->values->oneOf($data['type'] ?? null, self::FILL_TYPES, self::FILL_NONE);
    }
}
