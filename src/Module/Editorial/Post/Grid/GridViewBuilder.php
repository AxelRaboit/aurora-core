<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Grid;

use Aurora\Core\Content\ContentValueNormalizer;
use Aurora\Core\Content\EmbedResolver;
use Aurora\Core\Content\VideoEmbedResolver;
use Aurora\Module\Configuration\Theme\Service\SurfaceContrast;
use Aurora\Module\Editorial\GitHub\Service\GitHubActivityView;
use Aurora\Module\Editorial\Post\Banner\BannerViewBuilder;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Entity\PostTranslationInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\Post\Service\BlocksRenderer;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Joins the two halves of a content grid into what a template can render.
 *
 * The arrangement is stored on the post and what fills it on one translation;
 * a template wants neither, it wants zones carrying both. Merging here is what
 * keeps Twig from knowing the split exists - the same arrangement the banner
 * uses, and the reason its partial survived the storage changing underneath.
 *
 * Every id is resolved in one query per kind rather than one per zone: a page
 * of ten pictures should cost one document lookup, not ten. That is why the
 * loading stays here and the building of each zone does not: the families of
 * zones live beside it - {@see ZoneListingViews}, {@see ZoneMediaViews},
 * {@see ZoneSiteViews}, {@see ZoneWidgetViews}, {@see IntegrationZoneViews} -
 * and are handed what this class already loaded, so none of them fetches per
 * zone. Each template still reads its own key of the zone: splitting the
 * builder did not have to touch a single one of them.
 */
final readonly class GridViewBuilder
{
    public function __construct(
        private GridNormalizer $gridNormalizer,
        private ContentValueNormalizer $values,
        private DocumentRepository $documentRepository,
        private PostRepository $postRepository,
        private BlocksRenderer $blocksRenderer,
        private VideoEmbedResolver $videoEmbedResolver,
        private EmbedResolver $embedResolver,
        private Security $security,
        private GitHubActivityView $gitHubActivityView,
        private ZoneWidgetViews $widgetViews,
        private SurfaceContrast $surfaceContrast,
        private IntegrationZoneViews $integrationViews,
        private ZoneListingViews $listingViews,
        private ZoneMediaViews $mediaViews,
        private ZoneSiteViews $siteViews,
        private BannerViewBuilder $bannerViews,
    ) {}

    /**
     * @param array<string, mixed> $layout  the post's raw column value
     * @param array<string, mixed> $content the translation's raw column value
     *
     * @return array<string, mixed>|null null when the grid is off or empty, so
     *                                   the template falls back to plain blocks
     */
    public function build(array $layout, array $content, string $locale, ?int $currentPostId = null): ?array
    {
        $grid = $this->resolve($layout, $content, $locale, $currentPostId);

        if (true !== $grid['enabled'] || [] === $grid['zones']) {
            return null;
        }

        return $grid;
    }

    /**
     * The editor needs the same resolved zones, but unconditionally: a grid
     * that is switched off still has to render its form.
     *
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    public function buildForEditor(array $layout, array $content, string $locale, ?int $currentPostId = null): array
    {
        return $this->resolve($layout, $content, $locale, $currentPostId, forEditor: true);
    }

    /**
     * One language's grid content as the editor reads it.
     *
     * The stored shape, untouched, except in header zones: their words carry
     * pictures of this language's own, and the pickers want those with a
     * preview rather than as bare ids - as the page's own banner gets them.
     *
     * @param array<string, mixed> $rawLayout  the post's raw column value
     * @param array<string, mixed> $rawContent the translation's raw column value
     *
     * @return array<string, mixed>
     */
    public function contentForEditor(array $rawLayout, array $rawContent): array
    {
        $banners = array_filter(
            GridNormalizer::flatten($this->gridNormalizer->normalizeLayout($rawLayout)['zones']),
            static fn (array $zone): bool => GridNormalizer::ZONE_BANNER === $zone['type'],
        );

        if ([] === $banners || !is_array($rawContent['zones'] ?? null)) {
            return $rawContent;
        }

        foreach ($banners as $zone) {
            $held = $rawContent['zones'][$zone['id']] ?? null;

            if (is_array($held)) {
                $rawContent['zones'][$zone['id']]['banner'] = $this->bannerViews->textsForEditor(
                    $zone['banner'] ?? [],
                    is_array($held['banner'] ?? null) ? $held['banner'] : [],
                );
            }
        }

        return $rawContent;
    }

    /**
     * @param array<string, mixed> $rawLayout
     * @param array<string, mixed> $rawContent
     * @param bool                 $forEditor  whether the zones are going to a
     *                                         screen that will send them back
     *
     * @return array<string, mixed>
     */
    private function resolve(array $rawLayout, array $rawContent, string $locale, ?int $currentPostId = null, bool $forEditor = false, int $depth = 0): array
    {
        $layout = $this->gridNormalizer->normalizeLayout($rawLayout);
        $content = $this->gridNormalizer->normalizeContent($rawContent, $layout);

        // Withheld zones go before anything else is worked out, and before
        // `place()` decides who sits where: a zone that is not drawn should not
        // leave a gap in the row it would have been on. The editor sees them
        // all - an author cannot arrange what the panel hides from them, and a
        // zone waiting for its date has to stay editable until it arrives.
        if (!$forEditor) {
            $layout['zones'] = $this->visibleOnly($layout['zones']);
        }

        $documents = $this->documents($layout);
        $posts = $this->posts($layout);

        $resolve = function (array $zone) use (&$resolve, $layout, $content, $documents, $posts, $locale, $currentPostId, $forEditor, $depth): array {
            $held = $content['zones'][$zone['id']];

            return [
                ...$zone,
                // Inheritance resolved here and nowhere else: the stored zone
                // goes on saying `inherit`, so changing the page's answer
                // moves every zone that never disagreed with it. A theme
                // reads one value and has no rule to apply.
                'reveal' => GridNormalizer::ZONE_REVEALS[0] === $zone['reveal']
                    ? $layout['reveal']
                    : $zone['reveal'],
                'caption' => $held['caption'],
                // Resolved only when the zone actually uses it: a picture
                // behind a section is the one case here that costs a document
                // lookup, and ninety-five of every hundred zones name none.
                'background' => GridNormalizer::SURFACE_CUSTOM === $zone['surface']
                    ? $this->mediaViews->zoneBackgroundView($zone['background'], $documents)
                    : null,
                // Empty for 'auto', which is every zone that never asked to
                // override the page - the template then poses no style at
                // all, exactly as before this existed.
                'contrastStyle' => 'auto' !== $zone['contrast'] ? $this->contrastStyle($zone['contrast']) : '',
                // Null when the zone follows the page, which is every zone
                // written before this existed - the template then adds nothing.
                'highlight' => $this->zoneHighlight($zone['highlight'] ?? 'inherit', $zone['highlightColor'] ?? null),
                'highlightStyle' => 'custom' === $this->zoneHighlight($zone['highlight'] ?? 'inherit', $zone['highlightColor'] ?? null)
                    ? '--zone-highlight: '.$zone['highlightColor'].';'
                    : '',
                'spanStyle' => $this->values->spanStyle($zone['span']),
                'ratioStyle' => $this->mediaViews->ratioStyle($zone['ratio']),
                // Empty at full width, which is every zone that has not asked
                // for anything - so a theme reading this puts no style on the
                // figure at all unless there is something to say. The margin
                // travels with the width because it only means anything
                // alongside it: a picture filling its zone has no side to sit
                // on, and emitting one would be answering a question nobody
                // asked.
                'scaleStyle' => GridNormalizer::SCALE_FULL === $zone['scale']
                    ? ''
                    : sprintf(
                        'width: %d%%; margin-inline: %s;',
                        $zone['scale'],
                        $this->marginFor($zone['align']),
                    ),
                // A stack's own children, resolved the same way. The
                // recursion is bounded by the normaliser, which refuses a
                // stack inside a stack - so this descends once and stops.
                'children' => $this->shareOut(array_map($resolve, $zone['children'] ?? [])),
                // What a child contributes to the stack's height, as a
                // share out of 48. `flex-basis: 0` is what makes the grow
                // factors read as exact proportions rather than as a split
                // of whatever space is left over; the absence of a
                // `min-height: 0` is what stops a child from ever being
                // squeezed under its own content. Proportions when the
                // content fits, growth when it does not, clipping never.
                'shareStyle' => sprintf(
                    'flex-grow: %d; flex-basis: 0;',
                    $zone['span']['lg'] ?? $zone['span']['base'] ?? GridNormalizer::COLUMNS,
                ),
                // One key per type, null for the others. The template reads
                // the one its type names, and a zone whose target has been
                // deleted since renders as nothing rather than as a hole
                // with a broken image in it.
                'html' => GridNormalizer::ZONE_TEXT === $zone['type']
                    ? $this->blocksRenderer->render($held['blocks'], $locale)
                    : null,
                'media' => GridNormalizer::ZONE_MEDIA === $zone['type']
                    ? $this->mediaViews->mediaData($documents[$zone['mediaId']] ?? null, $held['alt'], $zone['mediaUrl'] ?? null)
                    : null,
                'post' => GridNormalizer::ZONE_POST === $zone['type']
                    ? $this->listingViews->postCard($posts[$zone['postId']] ?? null, $locale)
                    : null,
                'video' => GridNormalizer::ZONE_VIDEO === $zone['type']
                    ? $this->videoEmbedResolver->resolve($held['url'])
                    : null,
                // A film the site hosts itself, picked from the library like a
                // picture. The embed and this are alternatives, not a pair: an
                // address goes to a provider's player, a document is played by
                // the browser. The zone offers both because the answer differs
                // per project - a client's showreel has no business on YouTube,
                // and a conference talk has no business on their server.
                'file' => GridNormalizer::ZONE_VIDEO === $zone['type']
                    ? $this->mediaViews->videoFile($documents[$zone['mediaId']] ?? null)
                    : null,
                // The same library, read as a recording rather than as a film.
                // Its own key rather than sharing `file`: the two carry
                // different things - a film has a poster and a pixel size, a
                // recording has neither - and one key holding two shapes is a
                // template guessing which it got.
                'audio' => GridNormalizer::ZONE_AUDIO === $zone['type']
                    ? $this->mediaViews->audioFile($documents[$zone['mediaId']] ?? null)
                    : null,
                // A file to take away. The words on the card are translated,
                // like a button's: the same plaquette is "Download the
                // brochure" on one page and "Télécharger la plaquette" on the
                // other, and the file underneath does not change.
                'document' => GridNormalizer::ZONE_DOCUMENT === $zone['type']
                    ? $this->mediaViews->documentCard($documents[$zone['mediaId']] ?? null, $held['label'])
                    : null,
                // Kept beside the embed so a zone whose address belongs to
                // no known provider can still offer the link rather than
                // silently showing nothing. A button reads the same two keys:
                // it is an address with a word on it.
                'url' => in_array($zone['type'], [
                    GridNormalizer::ZONE_VIDEO,
                    GridNormalizer::ZONE_BUTTON,
                    GridNormalizer::ZONE_EMBED,
                ], true)
                    ? $held['url']
                    : null,
                // A button with no words is a control nobody can read, and one
                // with nowhere to go is worse than absent - so both or
                // neither, decided here rather than by the template.
                //
                // A button that opens a window needs its words and something
                // to show: a link it no longer follows is not asked for, and a
                // window with nothing in it would be a dead control too.
                'button' => $this->buttonView($zone, $held, $locale),
                // Two readers, two shapes, one key - and the key belongs to
                // the stored list, which is what the editor sends back. A
                // page reads the entries with their words; the editor reads
                // the list it will hand over again, and giving it the view
                // instead cost a page its item texts: the arrangement it
                // returned had no ids the words could hang on.
                'items' => GridNormalizer::ZONE_ITEMS === $zone['type']
                    ? ($forEditor
                        ? $this->mediaViews->itemsForEditor($zone, $documents)
                        : $this->mediaViews->itemsView($zone, $held, $documents))
                    : null,
                'postList' => GridNormalizer::ZONE_POST_LIST === $zone['type']
                    ? $this->listingViews->postListView($zone, $locale, $currentPostId)
                    : null,
                'embed' => GridNormalizer::ZONE_EMBED === $zone['type']
                    ? $this->embedResolver->resolve($held['url'])
                    : null,
                'tabs' => GridNormalizer::ZONE_TABS === $zone['type']
                    ? $this->siteViews->tabsView($zone, $held, $locale, $forEditor)
                    : null,
                'search' => GridNormalizer::ZONE_SEARCH === $zone['type']
                    ? $this->siteViews->searchView($zone, $locale)
                    : null,
                'comments' => GridNormalizer::ZONE_COMMENTS === $zone['type']
                    ? $this->siteViews->commentsView($currentPostId, $locale)
                    : null,
                'deck' => GridNormalizer::ZONE_DECK === $zone['type']
                    ? $this->siteViews->deckView($zone['deckId'])
                    : null,
                'shared' => GridNormalizer::ZONE_SHARED === $zone['type']
                    ? $this->sharedView($posts[$zone['postId']] ?? null, $locale, $depth)
                    : null,
                'compare' => GridNormalizer::ZONE_COMPARE === $zone['type']
                    ? $this->mediaViews->compareView($zone, $held, $documents)
                    : null,
                'gallery' => match (true) {
                    GridNormalizer::ZONE_GALLERY === $zone['type'] => $this->mediaViews->galleryView($zone, $documents),
                    // The editor arranges a wall of films with the gallery's own
                    // controls - add, remove, reorder - so it is handed the
                    // films in the gallery's shape. The page reads `videoWall`.
                    GridNormalizer::ZONE_VIDEO_WALL === $zone['type'] && $forEditor => ['items' => array_map(
                        fn (int $id): array => ['url' => $this->mediaViews->videoFile($documents[$id] ?? null)['url'] ?? null],
                        $zone['mediaIds'],
                    )],
                    GridNormalizer::ZONE_TRAVEL_MAP === $zone['type'] && $forEditor => ['items' => array_map(
                        fn (int $id): array => ['url' => $this->mediaViews->mediaData($documents[$id] ?? null, '')['url'] ?? null],
                        $zone['mediaIds'],
                    )],
                    default => null,
                },
                'map' => GridNormalizer::ZONE_MAP === $zone['type']
                    ? $this->mediaViews->mapView($held['label'], $held['caption'], $documents[$zone['mediaId']] ?? null)
                    : null,
                'terms' => GridNormalizer::ZONE_TERMS === $zone['type']
                    ? $this->siteViews->termsView($zone, $locale)
                    : null,
                'form' => GridNormalizer::ZONE_FORM === $zone['type']
                    ? $this->siteViews->formView($zone['formId'], $locale)
                    : null,
                // Handed over untouched: Twig escapes it on the way out, and
                // nothing between here and there is allowed to reformat a
                // snippet whose whitespace is its meaning.
                'code' => GridNormalizer::ZONE_CODE === $zone['type'] ? $held['code'] : null,
                // Filled after the walk, once every text zone has been
                // rendered: a summary of a page cannot be written while the
                // page is still being read.
                'toc' => GridNormalizer::ZONE_TOC === $zone['type'] ? [] : null,
                'githubActivity' => GridNormalizer::ZONE_GITHUB_ACTIVITY === $zone['type']
                    ? $this->gitHubActivityView->build($locale, $zone['options'])
                    : null,
                'videoWall' => GridNormalizer::ZONE_VIDEO_WALL === $zone['type']
                    ? array_values(array_filter(array_map(
                        fn (int $id): ?array => $this->mediaViews->videoFile($documents[$id] ?? null),
                        $zone['mediaIds'],
                    )))
                    : null,
                'activityFeed' => GridNormalizer::ZONE_ACTIVITY_FEED === $zone['type']
                    ? $this->listingViews->activityFeedView($zone, $locale, $currentPostId)
                    : null,
                'travelMap' => GridNormalizer::ZONE_TRAVEL_MAP === $zone['type']
                    ? $this->mediaViews->travelMapView($zone, $held, $documents)
                    : null,
                'instagramFeed' => GridNormalizer::ZONE_INSTAGRAM_FEED === $zone['type']
                    ? $this->integrationViews->instagramFeed($zone['options'])
                    : null,
                'googleReviews' => GridNormalizer::ZONE_GOOGLE_REVIEWS === $zone['type']
                    ? $this->integrationViews->googleReviews($locale)
                    : null,
                'widget' => $this->widgetViews->build($zone, $held, $locale, fn (?int $id): ?array => null === $id ? null : $this->mediaViews->mediaData($documents[$id] ?? null, $held['alt']), $currentPostId),
                'newsletterPrivacy' => GridNormalizer::ZONE_NEWSLETTER_PRIVACY === $zone['type']
                    ? $this->integrationViews->newsletterPrivacy($held)
                    : null,
                // A header in the body. The editor gets the design with its
                // pictures resolved, the same shape the page's own banner
                // panel works on; the page gets it ready to draw, or nothing.
                'banner' => GridNormalizer::ZONE_BANNER === $zone['type']
                    ? ($forEditor
                        ? $this->bannerViews->buildForEditor($zone['banner'] ?? [], [])
                        : $this->bannerViews->buildEmbedded($zone['banner'] ?? [], $held['banner'] ?? []))
                    : null,
                'newsletterSignup' => GridNormalizer::ZONE_NEWSLETTER_SIGNUP === $zone['type']
                    ? $this->integrationViews->newsletterSignup($held, $locale)
                    : null,
            ];
        };

        $zones = array_map($resolve, $layout['zones']);

        // Applied after the zones are resolved rather than inside the walk,
        // because where a zone lands is a fact about the row it shares and not
        // about the zone: it cannot be known while resolving one at a time.
        //
        // Large screen only. Below that breakpoint every zone is full width, so
        // there is nothing to arrange, and the stylesheet reads an unset
        // property as `auto` - which is the plain flow this started as, and
        // what a theme that never emits this still gets.
        foreach (GridNormalizer::place($layout['zones']) as $index => $place) {
            $zones[$index]['startStyle'] = sprintf(
                '--row-lg: %d; --start-lg: %d;',
                $place['row'],
                $place['column'],
            );
        }

        $zones = $this->summarise($zones);

        // A plain loop rather than `array_map`: an arrow function captures by
        // value, so the list it filled would be the one it threw away.
        $lightbox = [];
        foreach ($zones as $index => $zone) {
            $zones[$index] = $this->numberForLightbox($zone, $lightbox);
        }

        return [
            ...$layout,
            'zones' => $zones,
            // Read by the post template, which draws the thread at the foot of
            // the page unless the grid has already placed it. Without this a
            // page carrying the zone would show the same conversation twice,
            // and the second one would be the one nobody asked for.
            'hasComments' => $this->holdsComments($zones),
            // The pictures of this grid, in the order a reader meets them, for
            // the one overlay the page mounts. Empty on a page with no picture,
            // which is what the template checks before mounting anything.
            'lightbox' => $lightbox,
        ];
    }

    /**
     * A button zone as the template draws it: a link, or a window.
     *
     * @param array<string, mixed> $zone
     * @param array<string, mixed> $held
     *
     * @return array{label: string, url: string|null, modal: array{title: string, html: string}|null}|null
     */
    private function buttonView(array $zone, array $held, string $locale): ?array
    {
        if (GridNormalizer::ZONE_BUTTON !== $zone['type'] || null === $held['label'] || '' === $held['label']) {
            return null;
        }

        if ('modal' === ($zone['options']['buttonAction'] ?? 'link')) {
            $html = $this->blocksRenderer->render(is_array($held['blocks'] ?? null) ? $held['blocks'] : [], $locale);

            if ('' === mb_trim(strip_tags($html, '<img><iframe><svg><table>'))) {
                return null;
            }

            return ['label' => $held['label'], 'url' => null, 'modal' => ['title' => (string) ($held['caption'] ?? ''), 'html' => $html]];
        }

        if (null === $held['url'] || '' === $held['url']) {
            return null;
        }

        return ['label' => $held['label'], 'url' => $held['url'], 'modal' => null];
    }

    /**
     * Lists the page's headings for whichever zones asked for a summary.
     *
     * Two passes over the same zones, and they have to be in this order: the
     * anchors are written into the text zones first, because a summary is a
     * list of links and a link needs something to land on. Nothing happens at
     * all when no zone asked - a page keeps exactly the markup it had.
     *
     * Ids are numbered rather than slugged from the words. Two sections called
     * "Tarifs" on one page would be two anchors with one name, and a reader
     * following the second would land on the first; a number cannot collide,
     * and nobody reads these.
     *
     * Only `<h2>` and `<h3>`: the page's title is its `<h1>`, and past the
     * third level a summary is longer than what it summarises. The pattern
     * matches the headings this renderer writes - bare, no attributes - so a
     * heading inside a raw HTML block keeps whatever its author gave it.
     *
     * @param list<array<string, mixed>> $zones
     *
     * @return list<array<string, mixed>>
     */
    private function summarise(array $zones): array
    {
        $wanted = false;
        foreach ($zones as $zone) {
            if (GridNormalizer::ZONE_TOC === $zone['type']) {
                $wanted = true;

                break;
            }
        }

        if (!$wanted) {
            return $zones;
        }

        $headings = [];
        $number = 0;
        $zones = $this->anchorHeadings($zones, $headings, $number);

        foreach ($zones as $index => $zone) {
            if (GridNormalizer::ZONE_TOC === $zone['type']) {
                $zones[$index]['toc'] = $headings;
            }
        }

        return $zones;
    }

    /**
     * Writes an anchor into every `<h2>` and `<h3>` of the text zones, in
     * reading order, and lists them.
     *
     * A stack's zones are read where the stack stands: a heading set beside a
     * picture is still a section of the page, and the summary skipped it for
     * as long as only the top level was read.
     *
     * @param list<array<string, mixed>> $zones
     * @param list<array<string, mixed>> $headings
     *
     * @return list<array<string, mixed>>
     */
    private function anchorHeadings(array $zones, array &$headings, int &$number): array
    {
        foreach ($zones as $index => $zone) {
            if (is_array($zone['children'] ?? null) && [] !== $zone['children']) {
                $zones[$index]['children'] = $this->anchorHeadings($zone['children'], $headings, $number);

                continue;
            }

            if (GridNormalizer::ZONE_TEXT !== $zone['type']) {
                continue;
            }

            if (!is_string($zone['html'] ?? null)) {
                continue;
            }

            $zones[$index]['html'] = preg_replace_callback(
                '#<h([23])>(.*?)</h\1>#s',
                static function (array $match) use (&$headings, &$number): string {
                    $id = sprintf('section-%d', ++$number);
                    $headings[] = [
                        'id' => $id,
                        'level' => (int) $match[1],
                        // The words without their markup: a heading may carry a
                        // link or a marker, and neither belongs in a summary.
                        'text' => mb_trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                    ];

                    return sprintf('<h%s id="%s">%s</h%s>', $match[1], $id, $match[2], $match[1]);
                },
                $zone['html'],
            ) ?? $zone['html'];
        }

        return $zones;
    }

    /**
     * Gives every picture of the grid its place in the overlay's order.
     *
     * The zone keeps only the number, and the pictures travel separately: the
     * overlay is mounted once for the whole grid, and stepping from one to the
     * next means the pictures have to be a list somewhere rather than one
     * payload per zone.
     *
     * A stack's children are pictures too, and are read in the order they are
     * drawn - which is the order the overlay steps through them.
     *
     * @param array<string, mixed>       $zone
     * @param list<array<string, mixed>> $lightbox
     *
     * @return array<string, mixed>
     */
    private function numberForLightbox(array $zone, array &$lightbox): array
    {
        if (is_array($zone['children'] ?? null) && [] !== $zone['children']) {
            foreach ($zone['children'] as $index => $child) {
                $zone['children'][$index] = $this->numberForLightbox($child, $lightbox);
            }
        }

        // A gallery is many pictures in one zone, so the number goes on each
        // of them rather than on the zone. They are numbered here, in the
        // order they are drawn, which is the order the overlay steps through.
        if (GridNormalizer::ZONE_GALLERY === $zone['type'] && is_array($zone['gallery'] ?? null)) {
            foreach ($zone['gallery']['items'] as $index => $picture) {
                $zone['gallery']['items'][$index]['lightboxIndex'] = count($lightbox);
                $lightbox[] = [
                    'url' => $picture['url'],
                    'alt' => $picture['alt'] ?? '',
                    // A gallery's caption belongs to the zone as a whole, so
                    // there is nothing per picture to show under the overlay.
                    'caption' => '',
                ];
            }

            $zone['lightboxIndex'] = null;

            return $zone;
        }

        $media = $zone['media'] ?? null;

        if (GridNormalizer::ZONE_MEDIA !== $zone['type'] || !is_array($media)) {
            $zone['lightboxIndex'] = null;

            return $zone;
        }

        $zone['lightboxIndex'] = count($lightbox);
        $lightbox[] = [
            'url' => $media['url'],
            'alt' => $media['alt'] ?? '',
            // The caption belongs to the zone, not to the document: the same
            // picture says something else in another page.
            'caption' => $zone['caption'] ?? '',
        ];

        return $zone;
    }

    /**
     * Whether the grid places the comment thread itself.
     *
     * Stacks included: a thread tucked into a column is still the thread, and
     * the foot of the page should stay quiet either way.
     *
     * @param list<array<string, mixed>> $zones
     */
    private function holdsComments(array $zones): bool
    {
        foreach ($zones as $zone) {
            if (GridNormalizer::ZONE_COMMENTS === $zone['type']) {
                return true;
            }

            if (is_array($zone['children'] ?? null) && $this->holdsComments($zone['children'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The zones a visitor may actually be shown, right now.
     *
     * Two questions, and they are different in kind. A **date** is the same
     * for everybody and is answered against the server's day. An **audience**
     * is answered against who is asking, which is why this needs the security
     * context at all.
     *
     * A stack's children are filtered too: hiding a stack whose zones have all
     * expired would be right, but hiding one zone inside a stack that still
     * has others is what an author actually asked for.
     *
     * No page cache stands in front of this - there is no `framework.cache`
     * configured for responses - so "now" really is now. That is worth saying
     * because it is the assumption the whole feature rests on: put a reverse
     * proxy in front of the site and a promotion could outlive its date by
     * however long the cache keeps a page.
     *
     * @param list<array<string, mixed>> $zones
     *
     * @return list<array<string, mixed>>
     */
    private function visibleOnly(array $zones): array
    {
        $today = new DateTimeImmutable('today')->format('Y-m-d');
        $isMember = $this->security->isGranted('IS_AUTHENTICATED_FULLY');

        $visible = [];

        foreach ($zones as $zone) {
            $from = $zone['visibleFrom'] ?? null;
            $until = $zone['visibleUntil'] ?? null;

            if (is_string($from) && $today < $from) {
                continue;
            }

            // Inclusive: "until the 31st" means the 31st is still a day the
            // zone is drawn, which is what an author means when they type it.
            if (is_string($until) && $today > $until) {
                continue;
            }

            if ('members' === ($zone['audience'] ?? null) && !$isMember) {
                continue;
            }

            if (is_array($zone['children'] ?? null) && [] !== $zone['children']) {
                $zone['children'] = $this->visibleOnly($zone['children']);
            }

            $visible[] = $zone;
        }

        return $visible;
    }

    /**
     * Another publication's grid, resolved as if it had been written here.
     *
     * The block is rendered in the language of the **page it appears on**, not
     * in some language of its own: that is why the id is shared and this is
     * not. A block with nothing written in this language draws nothing, the
     * same answer a linked publication gives.
     *
     * `$depth` is the whole of the recursion guard. At the second level this
     * returns null whatever the zone names, so a block that shares a block
     * draws the first and stops - and two blocks naming each other terminate
     * instead of resolving until the process dies.
     *
     * Trashed blocks are refused. A page should not go on showing a band
     * somebody deleted, and the publication zone beside this one already takes
     * the same view.
     *
     * @return array<string, mixed>|null
     */
    private function sharedView(?PostInterface $post, string $locale, int $depth): ?array
    {
        if ($depth >= 1 || !$post instanceof PostInterface || $post->isTrashed()) {
            return null;
        }

        $translation = $post->getTranslation($locale);

        if (!$translation instanceof PostTranslationInterface) {
            return null;
        }

        $grid = $this->resolve(
            $post->getGridLayout(),
            $translation->getGrid(),
            $locale,
            $post->getId(),
            depth: $depth + 1,
        );

        return [] === $grid['zones'] ? null : $grid;
    }

    /**
     * @param array<string, mixed> $layout
     *
     * @return array<int, DocumentInterface>
     */
    private function documents(array $layout): array
    {
        $ids = [];
        // The whole tree, stacks included: a picture inside a stack must be
        // fetched by the same one query as the rest, not by a second one.
        foreach (GridNormalizer::flatten($layout['zones']) as $zone) {
            // A video zone too: it can play a film the library holds, and it
            // reads it from the same prefetch rather than from a query of its
            // own. Audio and document zones name a file the same way, so they
            // join the same query - a page offering four things to download
            // should cost one query, not five.
            $carriesMedia = in_array($zone['type'], [
                GridNormalizer::ZONE_MEDIA,
                GridNormalizer::ZONE_VIDEO,
                GridNormalizer::ZONE_AUDIO,
                GridNormalizer::ZONE_DOCUMENT,
                GridNormalizer::ZONE_MAP,
                GridNormalizer::ZONE_CONTACT_CARD,
                GridNormalizer::ZONE_SOCIAL_POST,
            ], true);

            // The face beside a social post, and the picture in the middle of a
            // QR code: in the same query as everything else.
            if (GridNormalizer::ZONE_SOCIAL_POST === $zone['type'] && null !== ($zone['options']['socialAvatarId'] ?? null)) {
                $ids[] = $zone['options']['socialAvatarId'];
            }

            if (GridNormalizer::ZONE_QR_CODE === $zone['type'] && null !== ($zone['options']['qrLogoId'] ?? null)) {
                $ids[] = $zone['options']['qrLogoId'];
            }

            // A custom surface's own picture, whatever the zone's type -
            // it sits behind the zone rather than inside it.
            if (null !== ($zone['background']['mediaId'] ?? null)) {
                $ids[] = $zone['background']['mediaId'];
            }

            if (null !== ($zone['background']['videoId'] ?? null)) {
                $ids[] = $zone['background']['videoId'];
            }

            // A gallery names many at once, and they join the same query as
            // everything else: twenty-four photographs on a page should cost
            // one lookup, which is the whole reason this prefetch exists.
            foreach (is_array($zone['mediaIds'] ?? null) ? $zone['mediaIds'] : [] as $galleryId) {
                $ids[] = $galleryId;
            }

            if ($carriesMedia && null !== $zone['mediaId']) {
                $ids[] = $zone['mediaId'];
            }

            // An item list can hold a dozen portraits or logos. They join the
            // same query as everything else rather than opening a second one.
            foreach (is_array($zone['items'] ?? null) ? $zone['items'] : [] as $item) {
                if (null !== ($item['mediaId'] ?? null)) {
                    $ids[] = $item['mediaId'];
                }
            }
        }

        $ids = array_values(array_unique($ids));

        if ([] === $ids) {
            return [];
        }

        $documents = [];
        foreach ($this->documentRepository->findBy(['id' => $ids]) as $document) {
            $documents[(int) $document->getId()] = $document;
        }

        return $documents;
    }

    /**
     * @param array<string, mixed> $layout
     *
     * @return array<int, PostInterface>
     */
    private function posts(array $layout): array
    {
        $ids = [];
        foreach (GridNormalizer::flatten($layout['zones']) as $zone) {
            $namesAPost = in_array($zone['type'], [
                GridNormalizer::ZONE_POST,
                GridNormalizer::ZONE_SHARED,
            ], true);

            if ($namesAPost && null !== $zone['postId']) {
                $ids[] = $zone['postId'];
            }
        }

        $ids = array_values(array_unique($ids));

        if ([] === $ids) {
            return [];
        }

        $posts = [];
        foreach ($this->postRepository->findForDisplay($ids) as $post) {
            $posts[(int) $post->getId()] = $post;
        }

        return $posts;
    }

    /**
     * How a stack divides its height between the zones it holds.
     *
     * Normally by their shares, which is what `shareStyle` already says. But a
     * zone set to **fill** claims what is left over instead, and that only
     * means something if its neighbours stop claiming a share of their own -
     * so they fall back to their own content height and the filling one takes
     * the rest.
     *
     * This is what an author asks for with a short paragraph beside a picture:
     * not "half each", which leaves a hole under three lines of text, but
     * "the text takes what it needs and the picture has the remainder". Shares
     * stop applying the moment one zone says it wants the remainder - one zone
     * cannot both leave room and take everything left.
     *
     * @param list<array<string, mixed>> $children
     *
     * @return list<array<string, mixed>>
     */
    private function shareOut(array $children): array
    {
        $fills = array_filter(
            $children,
            static fn (array $child): bool => GridNormalizer::RATIO_FILL === $child['ratio'],
        );

        if ([] === $fills) {
            return $children;
        }

        return array_map(
            static fn (array $child): array => [
                ...$child,
                'shareStyle' => GridNormalizer::RATIO_FILL === $child['ratio']
                    // Several filling zones split the remainder evenly, which
                    // is the only reading of "we both take what is left".
                    ? 'flex-grow: 1; flex-basis: 0;'
                    // Its own height, and no more. `flex-basis: auto` with no
                    // growth is what "as tall as what it says" means here.
                    : 'flex-grow: 0; flex-basis: auto;',
            ],
            $children,
        );
    }

    /**
     * Which side a picture narrower than its zone sits on.
     *
     * `margin-inline` rather than a class, for the reason the width beside it
     * is a declaration: both are values chosen at runtime, and Tailwind only
     * emits classes it can read in the source.
     */
    private function marginFor(string $align): string
    {
        return match ($align) {
            'left' => '0 auto',
            'right' => 'auto 0',
            default => 'auto',
        };
    }

    /**
     * @return array<string, mixed>|null null whenever there is no picture to
     *                                   draw, which the template reads as a
     *                                   zone that renders nothing
     */

    /**
     * The mode a zone's hovers take, or null to follow the page. A custom
     * mode without a colour follows the page too: the normaliser already
     * refused anything that was not a hex colour.
     */
    private function zoneHighlight(string $mode, ?string $color): ?string
    {
        if ('inherit' === $mode || ('custom' === $mode && null === $color)) {
            return null;
        }

        return $mode;
    }

    /**
     * The full token set for a forced scheme, as one CSS declaration string -
     * same shape as the surface's own `fillStyle`, so the template poses it
     * the same way, in a `style` attribute rather than a class.
     */
    private function contrastStyle(string $scheme): string
    {
        $declarations = [];
        foreach ($this->surfaceContrast->tokensForScheme($scheme) as $token => $value) {
            $declarations[] = $token.': '.$value.';';
        }

        return implode('', $declarations);
    }
}
