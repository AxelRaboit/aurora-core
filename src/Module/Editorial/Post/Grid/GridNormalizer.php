<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Grid;

use Aurora\Core\Content\ContentValueNormalizer;

/**
 * Normalises the content grid, which is stored in two halves like the banner.
 *
 * The **layout** lives on the post and is shared by every language: which
 * zones exist, in what order, how wide each one is, and what kind of thing
 * each holds. The **content** lives on each translation: the words, the alt
 * text, the video address.
 *
 * Where the line falls, and why each side is where it is:
 *
 * - A zone's **type** is shared. A zone that is text in French and a video in
 *   English is not one zone, and no reader would see the same page.
 * - A zone's **span** is shared, for the same reason the banner's is: the
 *   design is written once.
 * - A **linked publication** is shared. The post it points at has its own
 *   translations, so the renderer picks the right one - asking an editor to
 *   re-pick it per language is the drift this split exists to prevent.
 * - A **media id** is shared and its **alt text** is not. The picture is the
 *   same picture; describing it is writing.
 * - A **video address** is per language. A localised video has a localised
 *   URL, the same way the banner's first button pointed at
 *   `/fr/page/premiers-pas`.
 *
 * Zones **flow**: they sit in order, each claiming its span, and wrap when a
 * row is full. Resizing is changing a span, moving is reordering - which is
 * what the banner already does and what has no empty cells to reason about.
 *
 * Two annotations bend that flow without replacing it, both added once authors
 * asked for arrangements it could not express - a zone at the right of an
 * otherwise empty row, and a zone pushed below a neighbour it would happily sit
 * beside. `offset` names the column a zone starts on; `newRow` sends it to a
 * fresh row. Neither is a coordinate: the **row** is still the browser's to
 * choose, so a page still reads as one sequence and still collapses to one
 * column on a phone with nothing written to make that happen.
 *
 * Text zones carry Editor.js blocks and are the one thing here written raw:
 * they are sanitised at render, like `blocks` always has been. Everything else
 * is whitelisted on the way in.
 *
 * @phpstan-type GridZone array{id: string, type: string, span: array<string, int|null>, offset: int, newRow: bool, ratio: string, scale: int, align: string, mediaId: ?int, mediaUrl: ?string, postId: ?int, children: list<mixed>}
 * @phpstan-type GridZoneContent array{blocks: list<mixed>, alt: string, caption: string, url: ?string}
 */
final readonly class GridNormalizer
{
    /** Editor.js blocks, the body of the page as it is written today. */
    public const string ZONE_TEXT = 'text';

    /** Another publication, rendered as a card. */
    public const string ZONE_POST = 'post';

    /** A picture from the document library. */
    public const string ZONE_MEDIA = 'media';

    /** A video address - YouTube, Vimeo, Dailymotion. */
    public const string ZONE_VIDEO = 'video';

    /**
     * Zones stacked one above another, sharing the height of the row they sit
     * in.
     *
     * The one way to get a tall zone with two shorter ones beside it, and the
     * reason it is a zone type rather than a second dimension on the grid.
     * Making a zone span two rows would mean explicit placement - start column,
     * row span, empty cells to arbitrate, and no sensible answer for a phone.
     * A stack keeps every zone flowing in one sequence: it is one more zone,
     * that happens to hold others.
     *
     * Its children take their height from the row, because grid items stretch
     * to it. Nothing here declares a height, and nothing has to.
     */
    public const string ZONE_STACK = 'stack';

    public const int COLUMNS = ContentValueNormalizer::COLUMNS;

    /**
     * The snap the editor works in. Four means twelfths, which is how most
     * layouts are described; two and one are there for the cases twelfths
     * cannot express. An author's choice, not a constant - which is why it is
     * stored rather than assumed.
     */
    public const array SNAPS = [4, 2, 1];

    /** A picture at its own proportions, and what every zone starts as. */
    public const string RATIO_NATURAL = 'natural';

    /**
     * The shape a media zone is cropped to.
     *
     * This is the one vertical control the grid offers, and deliberately the
     * only one. A free height means `grid-row` spans over a fixed row height -
     * a real 2D grid, with empty cells to arbitrate and no sensible answer for
     * a phone. It also produces, on any screen other than the one the page was
     * drawn on, either clipped text or a band of nothing.
     *
     * A ratio covers what "resize vertically" is actually wanted for - two
     * images that line up, a row of even cards - and survives the phone, where
     * a 16:9 picture is simply a picture.
     *
     * `natural` first: the default has to be the behaviour already published.
     */
    /**
     * Not a ratio at all, and in this list because it answers the same question
     * an author is asking: "how tall is this picture?" It means "as tall as the
     * box you are in" - which is only ever imposed from outside, by the row a
     * zone shares or the stack it sits in. A picture alone on its row has
     * nothing to fill, so this reads as `natural` there and below the large
     * breakpoint, where every zone is alone.
     */
    public const string RATIO_FILL = 'fill';

    public const array RATIOS = [self::RATIO_NATURAL, '16x9', '4x3', '1x1', '3x4', self::RATIO_FILL];

    /**
     * How much of its zone's width a picture takes, as a percentage.
     *
     * The answer to "I want this image smaller, but still in proportion". A
     * width rather than a height on purpose: a percentage of the zone is
     * responsive where a height in pixels is not, and because the picture keeps
     * its own proportions, halving its width halves its height. Asking for the
     * height and asking for the width are the same question.
     *
     * Narrowing the zone instead would have done it too, and does something
     * else: it moves the neighbours. This leaves the zone where it is and only
     * changes what fills it.
     *
     * A whitelist rather than any number between 1 and 100, for the reason the
     * width fractions are a whitelist: these are the sizes anyone actually
     * picks, and they are easy to aim at.
     */
    public const array SCALES = [25, 33, 50, 66, 75, 100];

    /** Full width - a picture fills its zone unless told otherwise. */
    public const int SCALE_FULL = 100;

    /**
     * Which side a picture sits on once it is narrower than its zone.
     *
     * Only ever asked at less than full width, where the question exists at
     * all: a picture filling its zone has no side to be on. Centre first,
     * because that is what a smaller picture did before this was a choice, and
     * the default has to be the behaviour already published.
     */
    public const array ALIGNMENTS = ['center', 'left', 'right'];

    /**
     * A page is not a feed. High enough that nobody meets it while laying out
     * a real page, low enough that a runaway payload cannot turn one post into
     * an unbounded document.
     */
    private const int MAX_ZONES = 60;

    /**
     * A stack is a way to split one cell in two or three, not a second page.
     * Low enough that the shares stay meaningful - six zones sharing a row's
     * height are six slivers.
     *
     * Public because the editor mirrors it and `GridContractMirrorTest` holds
     * the two together. The same goes for the two type lists below: they are
     * vocabulary the canvas has to know, not an internal detail.
     */
    public const int MAX_STACK_CHILDREN = 6;

    /**
     * What a zone may be anywhere, including inside a stack.
     *
     * In the order the editor offers them, which is the order an author reads.
     * Nothing here depends on it - `oneOf` does not care - but the list is
     * mirrored in `usePostGrid.js`, and two lists that claim to be the same
     * should be the same. `GridContractMirrorTest` caught them disagreeing on
     * exactly this the first time it ran.
     */
    public const array LEAF_ZONE_TYPES = [self::ZONE_TEXT, self::ZONE_MEDIA, self::ZONE_POST, self::ZONE_VIDEO];

    /**
     * A stack is only allowed at the top level: depth stops at one.
     *
     * Nesting further would turn a page into a layout tree, where what a zone
     * renders as can no longer be read off the list - and every consumer of
     * this shape, from the canvas to the Twig, would have to recurse without
     * bound.
     */
    public const array ZONE_TYPES = [...self::LEAF_ZONE_TYPES, self::ZONE_STACK];

    public function __construct(
        private ContentValueNormalizer $values,
    ) {}

    /**
     * The arrangement, shared by every language.
     *
     * @param mixed $raw whatever the client sent
     *
     * @return array<string, mixed>
     */
    public function normalizeLayout(mixed $raw): array
    {
        $data = is_array($raw) ? $raw : [];

        return [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'snap' => $this->snap($data['snap'] ?? null),
            'zones' => $this->zones($data),
        ];
    }

    /**
     * What each zone holds, for one language.
     *
     * Takes the layout because that is what says which zones exist, and of
     * what type: a video zone has no blocks to keep, and keeping them would
     * mean carrying content no screen can show.
     *
     * @param mixed                $raw    whatever the client sent
     * @param array<string, mixed> $layout an already-normalised layout
     *
     * @return array<string, mixed> content keyed by zone id
     */
    public function normalizeContent(mixed $raw, array $layout): array
    {
        $data = is_array($raw) ? $raw : [];
        $stored = is_array($data['zones'] ?? null) ? $data['zones'] : [];

        // Read defensively: an empty layout is a legitimate argument - a post
        // with no grid - and reaching for a key that is not there would turn
        // it into a crash.
        $zones = is_array($layout['zones'] ?? null) ? $layout['zones'] : [];

        $content = [];

        // Flat, including what stacks hold: ids are unique across the tree, so
        // one map answers for every zone whatever its depth. A nested content
        // shape would have to be walked in step with the layout by everything
        // that reads it, for no gain.
        foreach (self::flatten($zones) as $zone) {
            if (!is_string($zone['id'] ?? null)) {
                continue;
            }

            $entry = is_array($stored[$zone['id']] ?? null) ? $stored[$zone['id']] : [];

            $content[$zone['id']] = [
                // Raw, like `blocks`: Editor.js owns this shape and the
                // sanitiser runs at render. Only text zones keep it, so
                // switching a zone to a video drops what no screen can show.
                'blocks' => self::ZONE_TEXT === ($zone['type'] ?? null) && is_array($entry['blocks'] ?? null)
                    ? array_values($entry['blocks'])
                    : [],
                'alt' => $this->values->text($entry['alt'] ?? null),
                'caption' => $this->values->text($entry['caption'] ?? null),
                'url' => $this->values->url($entry['url'] ?? null),
            ];
        }

        return ['zones' => $content];
    }

    /** An empty layout - what a post starts life with. */
    public function emptyLayout(): array
    {
        return $this->normalizeLayout([]);
    }

    /** Empty content - what a translation starts life with. */
    public function emptyContent(): array
    {
        return ['zones' => []];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function zones(array $data): array
    {
        $raw = is_array($data['zones'] ?? null) ? $data['zones'] : [];
        $used = [];

        return $this->zoneList($raw, $used, true);
    }

    /**
     * @param array<mixed>        $raw
     * @param array<string, true> $used ids already taken, across the whole tree
     *
     * @return list<array<string, mixed>>
     */
    private function zoneList(array $raw, array &$used, bool $allowStacks): array
    {
        $zones = [];
        $limit = $allowStacks ? self::MAX_ZONES : self::MAX_STACK_CHILDREN;

        foreach (array_slice(array_values($raw), 0, $limit) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            // An unknown type drops the zone rather than defaulting to text: a
            // page is better one zone short than showing an empty box where
            // something else was meant to be. Inside a stack the same applies
            // to a nested stack: depth stops at one, and the alternative - a
            // stack silently becoming a text zone - would be worse.
            $allowed = $allowStacks ? self::ZONE_TYPES : self::LEAF_ZONE_TYPES;
            $type = $this->values->oneOf($entry['type'] ?? null, $allowed, '');
            if ('' === $type) {
                continue;
            }

            // Ids are unique across the whole tree, not per level: content is
            // keyed by id in one flat map, so two zones sharing one would share
            // their words in every language at once.
            $id = $this->values->itemId($entry['id'] ?? null, $used);
            $used[$id] = true;

            // Every key is present whatever the type. Switching a zone from
            // media to text and back in the editor would otherwise lose what
            // was picked, and the front would have to guard every read.
            $zones[] = [
                'id' => $id,
                'type' => $type,
                // On a row this is a width; inside a stack it is a share of the
                // height. Both are a fraction of the space along the axis the
                // zone flows on, which is why one field says both.
                'span' => $this->values->span($entry['span'] ?? null),
                // Empty columns to the left, and a break before. Both are
                // arrangement, so both are shared; both are meaningless inside
                // a stack, where the axis of flow is vertical and there is no
                // row to start or to sit at the end of - hence `$allowStacks`,
                // which is only true at the top level.
                'offset' => $allowStacks
                    ? self::clampOffset($entry['offset'] ?? null, $this->values->span($entry['span'] ?? null))
                    : 0,
                'newRow' => $allowStacks && (bool) ($entry['newRow'] ?? false),
                // Shared, like the span: how a picture is cropped is design,
                // and the design is written once for every language.
                'ratio' => $this->values->oneOf($entry['ratio'] ?? null, self::RATIOS, self::RATIO_NATURAL),
                // Shared for the same reason the ratio is: how big a picture
                // is printed is design, written once for every language.
                'scale' => $this->scale($entry['scale'] ?? null),
                // Shared with the size it depends on: both are design.
                'align' => $this->values->oneOf($entry['align'] ?? null, self::ALIGNMENTS, self::ALIGNMENTS[0]),
                'mediaId' => $this->values->id($entry['mediaId'] ?? null),
                // An address, for a picture that is not in the library - a
                // placeholder service while a page is being drafted, or an
                // image already hosted elsewhere. Shared like the id, and for
                // the same reason: it is the same picture in every language.
                'mediaUrl' => $this->imageUrl($entry['mediaUrl'] ?? null),
                'postId' => $this->values->id($entry['postId'] ?? null),
                // Present on every zone, empty unless it is a stack - same
                // reasoning as the keys above, so nothing has to guard the read.
                'children' => self::ZONE_STACK === $type
                    ? $this->zoneList(is_array($entry['children'] ?? null) ? $entry['children'] : [], $used, false)
                    : [],
            ];
        }

        return $zones;
    }

    /**
     * Where each zone lands: its row and its first column, both 1-based and
     * ready for `--row-lg` and `--start-lg`.
     *
     * Mirrored by `placeZones` in `usePostGrid.js`, which the canvas uses, so
     * the picture in the editor and the published page are the same arithmetic
     * rather than two guesses that happen to agree. Two implementations of one
     * rule is a drift risk taken deliberately: the alternative is the editor
     * asking the server where its own boxes go, on every drag.
     *
     * **The row is worked out here rather than left to auto-placement**, which
     * was the first attempt and is not enough. A grid places an item with a
     * definite column in the first row where those columns are free - so a zone
     * asked to start a new row, whose columns happen to be free beside its
     * neighbour, was placed there and the break did nothing. Naming the row is
     * what makes it hold.
     *
     * That does not make this authoring in coordinates. An author writes a
     * sequence - add, widen, reorder - and these two numbers are read off it;
     * nothing here is stored, there are no empty cells to arbitrate, and below
     * the large breakpoint none of it is emitted at all.
     *
     * @param list<array<string, mixed>> $zones
     *
     * @return list<array{row: int, column: int}> one per zone, in order
     */
    public static function place(array $zones): array
    {
        $places = [];
        $row = 1;
        $used = 0;

        foreach ($zones as $zone) {
            $span = is_array($zone['span'] ?? null) ? self::largeSpan($zone['span']) : self::COLUMNS;
            $offset = self::clampOffset($zone['offset'] ?? null, is_array($zone['span'] ?? null) ? $zone['span'] : []);

            if (($zone['newRow'] ?? false) && $used > 0) {
                ++$row;
                $used = 0;
            }

            if ($offset > 0) {
                // A row fills from the left, so what is free on it is always
                // its tail. An asked-for column below the mark is therefore
                // taken, and the zone goes to the next row - where the same
                // column is free by definition.
                if ($offset < $used) {
                    ++$row;
                    $used = 0;
                }

                $start = $offset;
            } else {
                if ($used + $span > self::COLUMNS) {
                    ++$row;
                    $used = 0;
                }

                $start = $used;
            }

            $places[] = ['row' => $row, 'column' => $start + 1];
            $used = $start + $span;
        }

        return $places;
    }

    /**
     * The width a zone has on a large screen, following the same fallback chain
     * the stylesheet does: an unset breakpoint inherits the one below it.
     *
     * @param array<string, int|null> $span
     */
    private static function largeSpan(array $span): int
    {
        $columns = $span['lg'] ?? $span['md'] ?? $span['base'] ?? self::COLUMNS;

        return max(1, min(self::COLUMNS, $columns));
    }

    /**
     * An offset the row can actually hold.
     *
     * Bounded by what is left after the zone's own width, so `offset + span`
     * never exceeds the row. That is what lets {@see place()} take an asked-for
     * column at face value, and it is why widening a zone to the full row quietly
     * returns its offset to zero: there is no longer anywhere to be pushed to.
     *
     * @param array<string, int|null> $span
     */
    private static function clampOffset(mixed $value, array $span): int
    {
        $offset = is_numeric($value) ? (int) $value : 0;

        return max(0, min(self::COLUMNS - self::largeSpan($span), $offset));
    }

    /**
     * Every zone of a layout, stacks and what they hold, in reading order.
     *
     * Public because the view builder needs the same walk to batch its document
     * and post lookups: one query per kind for the whole page, stacks included,
     * rather than one per zone.
     *
     * @param array<mixed> $zones
     *
     * @return list<array<string, mixed>>
     */
    public static function flatten(array $zones): array
    {
        $flat = [];

        foreach ($zones as $zone) {
            if (!is_array($zone)) {
                continue;
            }

            $flat[] = $zone;

            if (is_array($zone['children'] ?? null)) {
                foreach ($zone['children'] as $child) {
                    if (is_array($child)) {
                        $flat[] = $child;
                    }
                }
            }
        }

        return $flat;
    }

    /**
     * An address that may go in an `src`.
     *
     * Narrower than {@see ContentValueNormalizer::url()}, which also accepts
     * `mailto:`, `tel:` and `#` - legitimate for a link and meaningless for a
     * picture. What is left is a path on this site or an http address, and the
     * scheme whitelist is what keeps `javascript:` out of an attribute the
     * browser will act on.
     */
    private function imageUrl(mixed $value): ?string
    {
        $url = $this->values->url($value);

        if (null === $url) {
            return null;
        }

        $lower = mb_strtolower($url);

        foreach (['/', 'http://', 'https://'] as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return $url;
            }
        }

        return null;
    }

    private function scale(mixed $value): int
    {
        $scale = is_numeric($value) ? (int) $value : 0;

        return in_array($scale, self::SCALES, true) ? $scale : self::SCALE_FULL;
    }

    private function snap(mixed $value): int
    {
        $snap = is_numeric($value) ? (int) $value : 0;

        return in_array($snap, self::SNAPS, true) ? $snap : self::SNAPS[0];
    }
}
