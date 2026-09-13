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
     * A sound file the library holds, played by the browser.
     *
     * The video zone's other half, and deliberately a type of its own rather
     * than a second branch inside it: a film offers an address to a provider
     * and a poster to stand in for it, and a recording has neither. One zone
     * answering both would ask an author which of two unrelated questions
     * they meant.
     */
    public const string ZONE_AUDIO = 'audio';

    /**
     * A file to take away, drawn as a card rather than played.
     *
     * The plaquette, the terms, the price list. Until now the only way to
     * offer one was a button pointed at an address typed by hand, which stops
     * working the day the file is replaced - the whole point of the library's
     * permalink is that it does not.
     *
     * Only a **published** document is ever drawn. A library holds a client's
     * internal papers alongside the ones they hand out, and the status column
     * is what already tells them apart.
     */
    public const string ZONE_DOCUMENT = 'document';

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

    /**
     * An address to follow, drawn as a button.
     *
     * A page that says what it offers and gives no way to act on it is a dead
     * end, and until now the only buttons in the product were the banner's -
     * available above the article and nowhere else.
     */
    public const string ZONE_BUTTON = 'button';

    /** Breathing room, or a rule across the column. */
    public const string ZONE_SEPARATOR = 'separator';

    /**
     * A short list of small things, drawn one of five ways.
     *
     * Steps, figures, questions, quotes and logos are one shape wearing five
     * costumes: a handful of entries, each with a couple of lines and
     * sometimes a picture. Five zone types would have been five normalisers,
     * five panels and five templates to keep in step; one type with a
     * `display` is one of each, and an author picks from a list instead of
     * hunting through a longer menu of zones.
     */
    public const string ZONE_ITEMS = 'items';

    /**
     * Publications chosen by rule rather than one by one.
     *
     * The `post` zone names a single publication and never changes its mind;
     * this one asks a question - the newest of this type, filed under this
     * term - and answers it again on every render. It is the difference
     * between a page that has to be edited when something is published and a
     * page that does not.
     */
    public const string ZONE_POST_LIST = 'postList';

    /**
     * A run of pictures from the library, in one zone.
     *
     * The publication already has a gallery, but there is exactly one of it and
     * it sits under the whole grid - so a page cannot put six photographs
     * between two paragraphs and then carry on. This can, and it is the zone a
     * portfolio page is mostly made of.
     *
     * It borrows the vocabulary the media zone already teaches rather than
     * inventing its own: `columns` says how many stand side by side, and
     * `ratio` answers "how tall is a picture" exactly as it does next door.
     * `natural` there means "its own proportions", and that is what tells this
     * zone to flow the pictures down columns instead of cropping them into a
     * grid - one setting, two mechanisms, and no third field to explain.
     */
    public const string ZONE_GALLERY = 'gallery';

    /**
     * Two pictures of one thing, and a handle between them.
     *
     * A renovation, a retouch, a site rebuilt: the demonstration that needs no
     * caption. It is the one zone here nobody else has, and the one a visitor
     * remembers.
     *
     * Two images and not a list, but stored in the same `mediaIds` the gallery
     * fills: the position carries the meaning - first is before, second is
     * after - and a second id field used by one type would have been a column
     * for a special case.
     */
    public const string ZONE_COMPARE = 'compare';

    /**
     * Where to find somebody, and how to get there.
     *
     * Deliberately **not** a map. A map that can be dragged means a tile
     * provider, which means a key and a third party watching the client's
     * visitors arrive - the very thing the integration zone exists to arbitrate
     * deliberately rather than by accident. This draws the address as an
     * address, offers a link that opens the reader's own map application, and
     * loads nothing from anybody.
     *
     * The picture, when there is one, is the author's: a photograph of the shop
     * front says more about finding the door than a pin on a grey rectangle.
     */
    public const string ZONE_MAP = 'map';

    /**
     * The terms of one taxonomy, each linking to its archive.
     *
     * The mirror of `postList`: that one draws the publications, this one
     * draws the doors that lead to them. On a hub page it replaces a menu
     * written by hand, which is out of date the first time a term is added -
     * the same argument the automatic list is built on.
     */
    public const string ZONE_TERMS = 'terms';

    /**
     * A form of the site, posed inside a page.
     *
     * A form already has a page of its own at `/{locale}/forms/{slug}`. This
     * puts the same form at the bottom of a service page, which is where
     * somebody who has just read what you offer is willing to fill one in -
     * rather than after a link that asks them to go somewhere else first.
     */
    public const string ZONE_FORM = 'form';

    /** A snippet, shown as written and coloured in the reader's browser. */
    public const string ZONE_CODE = 'code';

    /**
     * The page's own headings, listed and linked.
     *
     * The one zone with nothing to write in it: it reads the text zones of the
     * grid it sits in and lists what it finds. A long page needs a way in that
     * nobody has to maintain - a hand-written summary is out of date the first
     * time a section is renamed, and this one cannot be.
     */
    public const string ZONE_TOC = 'toc';

    /** How loudly a button is drawn. */
    public const array BUTTON_VARIANTS = ['solid', 'outline', 'ghost'];

    /** Shared by the button and the separator: both are a matter of degree. */
    public const array SIZES = ['sm', 'md', 'lg'];

    /** A rule, or the same room with nothing drawn in it. */
    public const array SEPARATOR_STYLES = ['line', 'space'];

    /**
     * The costumes of an item list.
     *
     * One zone rather than eight, because they are the same four fields asked
     * differently - a step's title is a figure's value is a question is an
     * offer's name is a colleague's name - and switching costume keeps what was
     * written. New ones go at the end: the first is the default, and moving it
     * would restyle every list already published.
     */
    public const array ITEM_DISPLAYS = ['steps', 'stats', 'faq', 'quotes', 'logos', 'timeline', 'offers', 'people'];

    /**
     * How densely a publication card is drawn. The same publication either
     * way: a full card carries its picture and its summary, a compact one is
     * a line and an arrow, a horizontal one sets the picture beside the text.
     */
    public const array CARD_VARIANTS = ['full', 'compact', 'horizontal'];

    /**
     * How many publications one automatic list may draw.
     *
     * A cap, not a recommendation: without one a zone could ask for the whole
     * site and the page would be an archive by accident. Twelve was too tight
     * for the one shape this zone is best at - a hub page whose cards are the
     * site's own sections - where the list is the page rather than a strip on
     * it, and the eight that did not fit disappeared with nothing said.
     */
    public const int MAX_LIST_LIMIT = 24;

    /**
     * The languages the highlighter is built with. An unknown one is not an
     * error - the snippet is shown as plain escaped text - but keeping the
     * list here lets the editor offer a dropdown instead of a free field
     * nobody can guess the vocabulary of.
     */
    public const array CODE_LANGUAGES = [
        'bash', 'css', 'html', 'javascript', 'json', 'markdown',
        'php', 'python', 'sql', 'typescript', 'yaml',
    ];

    /** How a text zone is set: body copy, a standfirst, or fine print. */
    public const array TEXT_SIZES = ['normal', 'lead', 'small'];

    /**
     * What a zone sits on.
     *
     * Nothing else in the grid draws a background, so a page of ten zones is
     * ten things on one flat sheet, in the same rhythm, none of them able to
     * read as a section. A surface is what turns a run of zones into a page:
     * a card lifts one out, a tint groups several, an accent says "this is
     * the one to act on".
     *
     * Shared, like the width beside it - a translated page does not repaint
     * its own sections.
     */
    public const array SURFACES = ['none', 'card', 'soft', 'accent'];

    /**
     * Enough for a process, a row of figures or a short FAQ, and few enough
     * that the list stays a list. Past this it is a page of its own.
     */
    public const int MAX_ITEMS = 12;

    /**
     * How many pictures one gallery zone may hold.
     *
     * The same number the automatic list is capped at, and for the same
     * reason: a cap rather than a recommendation. Without one a zone could
     * name the whole library and the page would become an archive by accident.
     * A page that needs more than this needs a second zone, which is also a
     * second place for the reader to breathe.
     */
    public const int MAX_GALLERY_IMAGES = 24;

    /**
     * Before and after. Not a setting - a third picture would have no place to
     * be, and the handle only ever separates two things.
     */
    public const int COMPARE_IMAGES = 2;

    /** How many entries sit side by side, where the display lays them out in a row. */
    public const array ITEM_COLUMNS = [2, 3, 4];

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
    public const array LEAF_ZONE_TYPES = [
        self::ZONE_TEXT,
        self::ZONE_MEDIA,
        self::ZONE_POST,
        self::ZONE_VIDEO,
        self::ZONE_AUDIO,
        self::ZONE_DOCUMENT,
        self::ZONE_TERMS,
        self::ZONE_MAP,
        self::ZONE_GALLERY,
        self::ZONE_COMPARE,
        self::ZONE_BUTTON,
        self::ZONE_SEPARATOR,
        self::ZONE_ITEMS,
        self::ZONE_POST_LIST,
        self::ZONE_FORM,
        self::ZONE_CODE,
        self::ZONE_TOC,
    ];

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
                // What a button says. `url` above is where it goes: a
                // localised page has a localised address, which is why both
                // halves of a button are translated.
                'label' => $this->values->text($entry['label'] ?? null),
                // Kept as typed - every space matters in a snippet, so this is
                // the one text field that is not trimmed. It is escaped at
                // render, never interpreted.
                'code' => is_string($entry['code'] ?? null) ? $entry['code'] : '',
                // The words of an item list, against the entries the layout
                // declares - so an entry removed from the arrangement takes
                // its words with it instead of leaving them behind unseen.
                'items' => $this->itemTexts($entry['items'] ?? null, $zone),
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
        $anchors = [];

        return $this->zoneList($raw, $used, $anchors, true);
    }

    /**
     * @param array<mixed>        $raw
     * @param array<string, true> $used    ids already taken, across the whole tree
     * @param array<string, true> $anchors anchors already taken, likewise - a
     *                                     link points at one zone, so a name
     *                                     belongs to one zone
     *
     * @return list<array<string, mixed>>
     */
    private function zoneList(array $raw, array &$used, array &$anchors, bool $allowStacks): array
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

            // The name a link may jump to. Separate from the id on purpose:
            // the id is the editor's handle on a zone and means nothing to a
            // reader, while this is written to be read in an address bar and
            // survives the zone being rebuilt.
            $anchor = $this->values->anchor($entry['anchor'] ?? null, $anchors);

            if ('' !== $anchor) {
                $anchors[$anchor] = true;
            }

            // Every key is present whatever the type. Switching a zone from
            // media to text and back in the editor would otherwise lose what
            // was picked, and the front would have to guard every read.
            // A zone that escapes its column and spans the viewport - a
            // picture, or the band a surface draws behind one. Only meaningful
            // at the top level: inside a stack there is no column to escape.
            $fullBleed = $allowStacks && (bool) ($entry['fullBleed'] ?? false);

            // Full bleed is drawn by pushing a viewport-wide box back by half
            // its own container, which only lands on the middle of the screen
            // when the container is the whole row. Left at 32 columns the band
            // centres on the middle of those 32 and hangs off to one side.
            //
            // So the width is not a question a full-bleed zone gets to answer.
            // Decided here rather than in the stylesheet, because the same
            // arrangement is read by the editor's preview, and two places
            // deciding it is two places to disagree.
            $span = $fullBleed
                ? array_fill_keys(ContentValueNormalizer::BREAKPOINTS, self::COLUMNS)
                : $this->values->span($entry['span'] ?? null);

            $zones[] = [
                'id' => $id,
                'anchor' => $anchor,
                'type' => $type,
                // On a row this is a width; inside a stack it is a share of the
                // height. Both are a fraction of the space along the axis the
                // zone flows on, which is why one field says both.
                'span' => $span,
                // Empty columns to the left, and a break before. Both are
                // arrangement, so both are shared; both are meaningless inside
                // a stack, where the axis of flow is vertical and there is no
                // row to start or to sit at the end of - hence `$allowStacks`,
                // which is only true at the top level.
                'offset' => $allowStacks
                    ? self::clampOffset($entry['offset'] ?? null, $span)
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
                // The pictures of a gallery, in the order they were arranged.
                // Shared like the single id beside it: which photographs a page
                // shows is not a matter of language.
                'mediaIds' => match ($type) {
                    self::ZONE_GALLERY => $this->mediaIdList($entry['mediaIds'] ?? null, self::MAX_GALLERY_IMAGES),
                    self::ZONE_COMPARE => $this->mediaIdList($entry['mediaIds'] ?? null, self::COMPARE_IMAGES),
                    default => [],
                },
                // An address, for a picture that is not in the library - a
                // placeholder service while a page is being drafted, or an
                // image already hosted elsewhere. Shared like the id, and for
                // the same reason: it is the same picture in every language.
                'mediaUrl' => $this->imageUrl($entry['mediaUrl'] ?? null),
                'postId' => $this->values->id($entry['postId'] ?? null),
                // How loudly a button is drawn, and how much room it or a
                // separator takes. Design, so shared - a translated page does
                // not restyle its own buttons.
                'variant' => $this->values->oneOf($entry['variant'] ?? null, self::BUTTON_VARIANTS, self::BUTTON_VARIANTS[0]),
                'size' => $this->values->oneOf($entry['size'] ?? null, self::SIZES, self::SIZES[1]),
                'separatorStyle' => $this->values->oneOf($entry['separatorStyle'] ?? null, self::SEPARATOR_STYLES, self::SEPARATOR_STYLES[0]),
                // Which costume an item list wears, and how many
                // entries stand side by side where the costume lays them in a
                // row. Both are design, both shared.
                'display' => $this->values->oneOf($entry['display'] ?? null, self::ITEM_DISPLAYS, self::ITEM_DISPLAYS[0]),
                'columns' => in_array((int) ($entry['columns'] ?? 0), self::ITEM_COLUMNS, true)
                    ? (int) $entry['columns']
                    : self::ITEM_COLUMNS[1],
                // The entries themselves, but only what is shared: how many
                // there are, in what order, and the picture each one carries.
                // Their words live on the translation, like every other word
                // on the page.
                'items' => self::ZONE_ITEMS === $type ? $this->itemList($entry['items'] ?? null) : [],
                // What a list zone asks for. Both filters are optional and
                // combine; null on either side means "do not narrow by this".
                // Which taxonomy a terms zone unrolls. Shared like every
                // other id here: a taxonomy carries its own translations, so
                // the renderer picks the right ones rather than asking an
                // author to name a different taxonomy per language.
                'taxonomyId' => $this->values->id($entry['taxonomyId'] ?? null),
                'postTypeId' => $this->values->id($entry['postTypeId'] ?? null),
                'termId' => $this->values->id($entry['termId'] ?? null),
                'limit' => min(self::MAX_LIST_LIMIT, max(1, (int) ($entry['limit'] ?? 3))),
                // Read by the single-publication zone too: the two draw the
                // same card, so they offer the same densities.
                'cardVariant' => $this->values->oneOf($entry['cardVariant'] ?? null, self::CARD_VARIANTS, self::CARD_VARIANTS[0]),
                // Which form the zone poses. Shared: a form carries its own
                // translations, so the page picks the right one rather than
                // naming a different form per language.
                'formId' => $this->values->id($entry['formId'] ?? null),
                // Which highlighter to ask for. Empty means "do not guess":
                // a snippet with no language named is shown as it was typed.
                'language' => in_array($entry['language'] ?? null, self::CODE_LANGUAGES, true)
                    ? (string) $entry['language']
                    : null,
                // How a text zone is set. Design, so shared - a standfirst is
                // a standfirst in every language.
                'textSize' => $this->values->oneOf($entry['textSize'] ?? null, self::TEXT_SIZES, self::TEXT_SIZES[0]),
                // Numbered lines down the side of a snippet. Off by default:
                // a three-line example needs no coordinates, and a page that
                // numbers everything makes the numbers mean nothing.
                'lineNumbers' => (bool) ($entry['lineNumbers'] ?? false),
                // One panel open at a time, for a list that folds. Off by
                // default, which is the behaviour already published: a reader
                // comparing two answers should not have the first close under
                // them because nobody asked for that.
                'exclusiveOpen' => (bool) ($entry['exclusiveOpen'] ?? false),
                // What the zone sits on. Every type can have one: a card of
                // figures, a tinted FAQ, a call to action on accent.
                'surface' => $this->values->oneOf($entry['surface'] ?? null, self::SURFACES, self::SURFACES[0]),
                // Decided above, because the width depends on it.
                'fullBleed' => $fullBleed,
                // Present on every zone, empty unless it is a stack - same
                // reasoning as the keys above, so nothing has to guard the read.
                'children' => self::ZONE_STACK === $type
                    ? $this->zoneList(is_array($entry['children'] ?? null) ? $entry['children'] : [], $used, $anchors, false)
                    : [],
            ];
        }

        return $zones;
    }

    /**
     * The translated half of an item list, keyed by entry id.
     *
     * Four fields for five displays, because they are the same four questions
     * asked differently: a step's title is a figure's value is a question is a
     * quote's author is a logo's name. The panel labels them for the display
     * it is showing; storing five shapes would have made switching display
     * throw the words away.
     *
     * @param array<string, mixed> $zone the already-normalised layout zone
     *
     * @return array<string, array<string, string|null>>
     */
    private function itemTexts(mixed $raw, array $zone): array
    {
        if (self::ZONE_ITEMS !== ($zone['type'] ?? null)) {
            return [];
        }

        $stored = is_array($raw) ? $raw : [];
        $texts = [];

        foreach (is_array($zone['items'] ?? null) ? $zone['items'] : [] as $item) {
            $id = is_string($item['id'] ?? null) ? $item['id'] : null;
            if (null === $id) {
                continue;
            }

            $entry = is_array($stored[$id] ?? null) ? $stored[$id] : [];

            $texts[$id] = [
                'title' => $this->values->text($entry['title'] ?? null),
                'description' => $this->values->text($entry['description'] ?? null),
                'caption' => $this->values->text($entry['caption'] ?? null),
                'url' => $this->values->url($entry['url'] ?? null),
            ];
        }

        return $texts;
    }

    /**
     * The pictures of a gallery, as a plain list of library ids.
     *
     * Order is the author's and is kept exactly: a gallery is read in the
     * order it was arranged, and sorting it here would silently rewrite a
     * sequence somebody composed.
     *
     * The same picture twice is refused. Not a matter of taste - the overlay
     * numbers the pictures of a grid in the order they are drawn, and two
     * entries pointing at one document would give a reader two stops on the
     * same photograph with no way to tell them apart.
     *
     * Capped like every other list here, and for the same reason: the payload
     * comes from a browser.
     *
     * @return list<int>
     */
    private function mediaIdList(mixed $raw, int $limit): array
    {
        $ids = [];

        foreach (is_array($raw) ? $raw : [] as $value) {
            $id = $this->values->id($value);
            if (null === $id) {
                continue;
            }

            if (in_array($id, $ids, true)) {
                continue;
            }

            $ids[] = $id;

            if (count($ids) >= $limit) {
                break;
            }
        }

        return $ids;
    }

    /**
     * The shared half of an item list: identity, order and picture.
     *
     * Ids are generated here when the client sends none, so a list built by
     * something other than the editor still joins up with its words. Capped
     * at {@see MAX_ITEMS} rather than trusted: the payload comes from a
     * browser, and a list of ten thousand entries is a page nobody can render.
     *
     * @return list<array{id: string, mediaId: int|null, featured: bool}>
     */
    private function itemList(mixed $raw): array
    {
        $entries = is_array($raw) ? $raw : [];
        $items = [];
        $used = [];

        foreach ($entries as $entry) {
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }

            $entry = is_array($entry) ? $entry : [];
            $id = is_string($entry['id'] ?? null) && '' !== $entry['id'] ? $entry['id'] : null;

            // A duplicate id would make two entries share one set of words,
            // and editing either would edit both.
            if (null === $id || isset($used[$id])) {
                $id = bin2hex(random_bytes(8));
            }

            $used[$id] = true;

            $items[] = [
                'id' => $id,
                'mediaId' => $this->values->id($entry['mediaId'] ?? null),
                // The one entry of an offer list drawn louder than the others.
                // Shared like the picture: which plan is recommended is the
                // same recommendation in every language.
                'featured' => (bool) ($entry['featured'] ?? false),
            ];
        }

        return $items;
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
