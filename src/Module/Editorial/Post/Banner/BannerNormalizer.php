<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Banner;

/**
 * Normalises the per-translation banner configuration.
 *
 * Every value that reaches the database goes through here, and anything not
 * explicitly allowed is dropped. That is a deliberate contrast with `blocks`,
 * which is written raw and only sanitised at render time — the reason two
 * block types have been shipping a shape the renderer cannot read without
 * anyone noticing. A banner that survives a round-trip is a banner the
 * renderer can trust.
 *
 * The banner is a list of items, each with a type and a width in columns.
 * "Text left, image right", "image only", "two images" and "two texts" are
 * then arrangements an author builds rather than a list this class enumerates.
 *
 * The width lives on a 48-column grid, and 48 is not arbitrary: it is 4 × 12
 * and 2 × 24, so a twelfth is 4 columns and a twenty-fourth is 2. Halves,
 * thirds, quarters, sixths and eighths all land on whole numbers. The same
 * grid carries the content layout, which is why the shape lives here rather
 * than being invented twice.
 *
 * @phpstan-type BannerSpan array{base: int, md: ?int, lg: ?int}
 * @phpstan-type BannerItem array{type: string, span: BannerSpan, title: string, description: string, titleColor: ?string, descriptionColor: ?string, align: string, mediaId: ?int, alt: string}
 */
final readonly class BannerNormalizer
{
    public const string ITEM_TEXT = 'text';

    public const string ITEM_IMAGE = 'image';

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
    public const int COLUMNS = 48;

    /**
     * A banner is a header, not a page. The cap is here so a runaway payload
     * cannot turn one into an unbounded list — the content grid is where many
     * items belong.
     */
    private const int MAX_ITEMS = 6;

    private const array ITEM_TYPES = [self::ITEM_TEXT, self::ITEM_IMAGE];

    private const array FILL_TYPES = [self::FILL_NONE, self::FILL_SOLID, self::FILL_GRADIENT];

    private const array HEIGHTS = ['sm', 'md', 'lg', 'full'];

    private const array WIDTHS = [self::WIDTH_CONTAINED, self::WIDTH_FULL_ALIGNED, self::WIDTH_FULL];

    private const array ALIGNMENTS = ['start', 'center', 'end'];

    private const array BREAKPOINTS = ['base', 'md', 'lg'];

    private const string HEX_COLOR = '/^#[0-9a-fA-F]{6}$/';

    /**
     * @param mixed $raw whatever the client sent
     *
     * @return array<string, mixed> a banner that is safe to persist and render
     */
    public function normalize(mixed $raw): array
    {
        $data = is_array($raw) ? $raw : [];

        return [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'height' => $this->oneOf($data['height'] ?? null, self::HEIGHTS, 'md'),
            // Where the banner sits: inside the article column like the rest of
            // the page, or spanning the viewport flush under the top bar.
            'width' => $this->oneOf($data['width'] ?? null, self::WIDTHS, self::WIDTH_CONTAINED),
            'logoMediaId' => $this->id($data['logoMediaId'] ?? null),
            'background' => $this->background(is_array($data['background'] ?? null) ? $data['background'] : []),
            'items' => $this->items($data),
        ];
    }

    /** An empty banner — what a translation starts life with. */
    public function empty(): array
    {
        return $this->normalize([]);
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
            'color' => $this->color($data['color'] ?? null),
            'gradientFrom' => $this->color($data['gradientFrom'] ?? null),
            'gradientTo' => $this->color($data['gradientTo'] ?? null),
            // Degrees, the CSS sense: 0 points up, 180 down.
            'gradientAngle' => max(0, min(360, (int) ($data['gradientAngle'] ?? 180))),
            'mediaId' => $this->id($data['mediaId'] ?? null),
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
    private function items(array $data): array
    {
        $raw = is_array($data['items'] ?? null)
            ? $data['items']
            : $this->itemsFromLegacySlots($data);

        $items = [];

        foreach (array_slice(array_values($raw), 0, self::MAX_ITEMS) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            // An unknown type drops the item rather than defaulting to text:
            // a banner is better short one element than showing an empty box
            // where something else was meant to be.
            $type = $this->oneOf($entry['type'] ?? null, self::ITEM_TYPES, '');
            if ('' === $type) {
                continue;
            }

            // Every key is present whatever the type. Switching an item from
            // text to image and back in the editor would otherwise lose what
            // was typed, and the front would have to guard every read.
            $items[] = [
                'type' => $type,
                'span' => $this->span(is_array($entry['span'] ?? null) ? $entry['span'] : []),
                'title' => $this->text($entry['title'] ?? null),
                'description' => $this->text($entry['description'] ?? null),
                'titleColor' => $this->color($entry['titleColor'] ?? null),
                'descriptionColor' => $this->color($entry['descriptionColor'] ?? null),
                'align' => $this->oneOf($entry['align'] ?? null, self::ALIGNMENTS, 'start'),
                'mediaId' => $this->id($entry['mediaId'] ?? null),
                'alt' => $this->text($entry['alt'] ?? null),
            ];
        }

        return $items;
    }

    /**
     * Banners written against the previous shape carry two fixed `slots` and a
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

    /**
     * Widths per breakpoint, smallest first. An absent step inherits the one
     * below it — which is why only `base` is guaranteed: an item that says
     * nothing is full width on a phone and stays full width until something
     * says otherwise.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, int|null>
     */
    private function span(array $raw): array
    {
        $span = [];

        foreach (self::BREAKPOINTS as $breakpoint) {
            $value = $raw[$breakpoint] ?? null;
            $span[$breakpoint] = is_numeric($value)
                ? max(1, min(self::COLUMNS, (int) $value))
                : null;
        }

        $span['base'] ??= self::COLUMNS;

        return $span;
    }

    /** @param array<string, mixed> $data */
    private function fillType(array $data): string
    {
        if (!array_key_exists('type', $data) && null !== $this->color($data['color'] ?? null)) {
            return self::FILL_SOLID;
        }

        return $this->oneOf($data['type'] ?? null, self::FILL_TYPES, self::FILL_NONE);
    }

    /** @param list<string> $allowed */
    private function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $fallback;
    }

    /**
     * Rejects anything that is not a six-digit hex. The renderer drops these
     * straight into a `style` attribute, so a loose value here is an
     * injection point rather than a cosmetic problem.
     */
    private function color(mixed $value): ?string
    {
        return is_string($value) && 1 === preg_match(self::HEX_COLOR, $value) ? mb_strtolower($value) : null;
    }

    private function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? mb_trim($value) : '';
    }
}
