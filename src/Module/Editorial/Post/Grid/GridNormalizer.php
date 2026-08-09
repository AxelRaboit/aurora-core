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
 *   translations, so the renderer picks the right one — asking an editor to
 *   re-pick it per language is the drift this split exists to prevent.
 * - A **media id** is shared and its **alt text** is not. The picture is the
 *   same picture; describing it is writing.
 * - A **video address** is per language. A localised video has a localised
 *   URL, the same way the banner's first button pointed at
 *   `/fr/page/premiers-pas`.
 *
 * Zones **flow**: they sit in order, each claiming its span, and wrap when a
 * row is full. They are not placed at coordinates. Resizing is changing a
 * span, moving is reordering — which is what the banner already does, what the
 * rest of the backend already does, and what has no empty cells to reason
 * about. Free placement can come later if it turns out to be missed; it cannot
 * be removed once shipped.
 *
 * Text zones carry Editor.js blocks and are the one thing here written raw:
 * they are sanitised at render, like `blocks` always has been. Everything else
 * is whitelisted on the way in.
 *
 * @phpstan-type GridZone array{id: string, type: string, span: array<string, int|null>, ratio: string, mediaId: ?int, postId: ?int}
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

    /** A video address — YouTube, Vimeo, Dailymotion. */
    public const string ZONE_VIDEO = 'video';

    public const int COLUMNS = ContentValueNormalizer::COLUMNS;

    /**
     * The snap the editor works in. Four means twelfths, which is how most
     * layouts are described; two and one are there for the cases twelfths
     * cannot express. An author's choice, not a constant — which is why it is
     * stored rather than assumed.
     */
    public const array SNAPS = [4, 2, 1];

    /** A picture at its own proportions, and what every zone starts as. */
    public const string RATIO_NATURAL = 'natural';

    /**
     * The shape a media zone is cropped to.
     *
     * This is the one vertical control the grid offers, and deliberately the
     * only one. A free height means `grid-row` spans over a fixed row height —
     * a real 2D grid, with empty cells to arbitrate and no sensible answer for
     * a phone. It also produces, on any screen other than the one the page was
     * drawn on, either clipped text or a band of nothing.
     *
     * A ratio covers what "resize vertically" is actually wanted for — two
     * images that line up, a row of even cards — and survives the phone, where
     * a 16:9 picture is simply a picture.
     *
     * `natural` first: the default has to be the behaviour already published.
     */
    public const array RATIOS = [self::RATIO_NATURAL, '16x9', '4x3', '1x1', '3x4'];

    /**
     * A page is not a feed. High enough that nobody meets it while laying out
     * a real page, low enough that a runaway payload cannot turn one post into
     * an unbounded document.
     */
    private const int MAX_ZONES = 60;

    private const array ZONE_TYPES = [self::ZONE_TEXT, self::ZONE_POST, self::ZONE_MEDIA, self::ZONE_VIDEO];

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

        // Read defensively: an empty layout is a legitimate argument — a post
        // with no grid — and reaching for a key that is not there would turn
        // it into a crash.
        $zones = is_array($layout['zones'] ?? null) ? $layout['zones'] : [];

        $content = [];

        foreach ($zones as $zone) {
            if (!is_array($zone)) {
                continue;
            }

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

    /** An empty layout — what a post starts life with. */
    public function emptyLayout(): array
    {
        return $this->normalizeLayout([]);
    }

    /** Empty content — what a translation starts life with. */
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

        $zones = [];
        $used = [];

        foreach (array_slice(array_values($raw), 0, self::MAX_ZONES) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            // An unknown type drops the zone rather than defaulting to text: a
            // page is better one zone short than showing an empty box where
            // something else was meant to be.
            $type = $this->values->oneOf($entry['type'] ?? null, self::ZONE_TYPES, '');
            if ('' === $type) {
                continue;
            }

            $id = $this->values->itemId($entry['id'] ?? null, $used);
            $used[$id] = true;

            // Every key is present whatever the type. Switching a zone from
            // media to text and back in the editor would otherwise lose what
            // was picked, and the front would have to guard every read.
            $zones[] = [
                'id' => $id,
                'type' => $type,
                'span' => $this->values->span($entry['span'] ?? null),
                // Shared, like the span: how a picture is cropped is design,
                // and the design is written once for every language.
                'ratio' => $this->values->oneOf($entry['ratio'] ?? null, self::RATIOS, self::RATIO_NATURAL),
                'mediaId' => $this->values->id($entry['mediaId'] ?? null),
                'postId' => $this->values->id($entry['postId'] ?? null),
            ];
        }

        return $zones;
    }

    private function snap(mixed $value): int
    {
        $snap = is_numeric($value) ? (int) $value : 0;

        return in_array($snap, self::SNAPS, true) ? $snap : self::SNAPS[0];
    }
}
