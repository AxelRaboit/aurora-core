<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Grid;

use Aurora\Core\Content\ContentValueNormalizer;
use Aurora\Core\Content\EmbedResolver;
use Aurora\Core\Content\VideoEmbedResolver;
use Aurora\Core\Storage\Enum\MimeGroupEnum;
use Aurora\Core\Storage\Enum\MimeTypeEnum;
use Aurora\Module\Configuration\Theme\Service\SurfaceContrast;
use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Entity\FormTranslationInterface;
use Aurora\Module\Editorial\Form\Repository\FormRepository;
use Aurora\Module\Editorial\Form\Serializer\FormSerializer;
use Aurora\Module\Editorial\GitHub\Service\GitHubActivityView;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Entity\PostTranslationInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\Post\Service\BlocksRenderer;
use Aurora\Module\Editorial\Post\Service\ThumbnailPresenter;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermTranslationInterface;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyRepository;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyTermRepository;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Service\DocumentCreditPresenter;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Module\Studio\Deck\Entity\DeckInterface;
use Aurora\Module\Studio\Deck\Repository\DeckRepository;
use Aurora\Module\Studio\Deck\Share\Repository\DeckShareLinkRepository;
use Collator;
use DateTimeImmutable;
use IntlDateFormatter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Joins the two halves of a content grid into what a template can render.
 *
 * The arrangement is stored on the post and what fills it on one translation;
 * a template wants neither, it wants zones carrying both. Merging here is what
 * keeps Twig from knowing the split exists - the same arrangement the banner
 * uses, and the reason its partial survived the storage changing underneath.
 *
 * Every id is resolved in one query per kind rather than one per zone: a page
 * of ten pictures should cost one document lookup, not ten.
 *
 * One class rather than a resolver per zone type. Four types is not enough to
 * earn an interface, and a per-type resolver would fetch its own rows, which
 * is exactly the N+1 the batching above avoids. When a project needs a zone
 * type of its own, that is the moment to invert this - not before.
 */
final readonly class GridViewBuilder
{
    /** How many publications an A to Z index lists at most. */
    private const int INDEX_LIMIT = 300;

    public function __construct(
        private GridNormalizer $gridNormalizer,
        private ContentValueNormalizer $values,
        private DocumentRepository $documentRepository,
        private DocumentUrlGenerator $documentUrlGenerator,
        private DocumentCreditPresenter $creditPresenter,
        private PostRepository $postRepository,
        private BlocksRenderer $blocksRenderer,
        private VideoEmbedResolver $videoEmbedResolver,
        private EmbedResolver $embedResolver,
        private ThumbnailPresenter $thumbnailPresenter,
        private FormRepository $formRepository,
        private FormSerializer $formSerializer,
        private TaxonomyRepository $taxonomyRepository,
        private TaxonomyTermRepository $taxonomyTermRepository,
        private UrlGeneratorInterface $urlGenerator,
        private PostTypeRepository $postTypeRepository,
        private DeckRepository $deckRepository,
        private DeckShareLinkRepository $deckShareLinkRepository,
        private Security $security,
        private GitHubActivityView $gitHubActivityView,
        private ZoneWidgetViews $widgetViews,
        private SurfaceContrast $surfaceContrast,
        private IntegrationZoneViews $integrationViews,
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
                    ? $this->zoneBackgroundView($zone['background'], $documents)
                    : null,
                // Empty for 'auto', which is every zone that never asked to
                // override the page - the template then poses no style at
                // all, exactly as before this existed.
                'contrastStyle' => 'auto' !== $zone['contrast'] ? $this->contrastStyle($zone['contrast']) : '',
                'spanStyle' => $this->values->spanStyle($zone['span']),
                'ratioStyle' => $this->ratioStyle($zone['ratio']),
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
                    ? $this->mediaData($documents[$zone['mediaId']] ?? null, $held['alt'], $zone['mediaUrl'] ?? null)
                    : null,
                'post' => GridNormalizer::ZONE_POST === $zone['type']
                    ? $this->postCard($posts[$zone['postId']] ?? null, $locale)
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
                    ? $this->videoFile($documents[$zone['mediaId']] ?? null)
                    : null,
                // The same library, read as a recording rather than as a film.
                // Its own key rather than sharing `file`: the two carry
                // different things - a film has a poster and a pixel size, a
                // recording has neither - and one key holding two shapes is a
                // template guessing which it got.
                'audio' => GridNormalizer::ZONE_AUDIO === $zone['type']
                    ? $this->audioFile($documents[$zone['mediaId']] ?? null)
                    : null,
                // A file to take away. The words on the card are translated,
                // like a button's: the same plaquette is "Download the
                // brochure" on one page and "Télécharger la plaquette" on the
                // other, and the file underneath does not change.
                'document' => GridNormalizer::ZONE_DOCUMENT === $zone['type']
                    ? $this->documentCard($documents[$zone['mediaId']] ?? null, $held['label'])
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
                'button' => GridNormalizer::ZONE_BUTTON === $zone['type']
                    && null !== $held['label'] && '' !== $held['label']
                    && null !== $held['url'] && '' !== $held['url']
                        ? ['label' => $held['label'], 'url' => $held['url']]
                        : null,
                // Two readers, two shapes, one key - and the key belongs to
                // the stored list, which is what the editor sends back. A
                // page reads the entries with their words; the editor reads
                // the list it will hand over again, and giving it the view
                // instead cost a page its item texts: the arrangement it
                // returned had no ids the words could hang on.
                'items' => GridNormalizer::ZONE_ITEMS === $zone['type']
                    ? ($forEditor
                        ? $this->itemsForEditor($zone, $documents)
                        : $this->itemsView($zone, $held, $documents))
                    : null,
                'postList' => GridNormalizer::ZONE_POST_LIST === $zone['type']
                    ? $this->postListView($zone, $locale, $currentPostId)
                    : null,
                'embed' => GridNormalizer::ZONE_EMBED === $zone['type']
                    ? $this->embedResolver->resolve($held['url'])
                    : null,
                'tabs' => GridNormalizer::ZONE_TABS === $zone['type']
                    ? $this->tabsView($zone, $held, $locale, $forEditor)
                    : null,
                'search' => GridNormalizer::ZONE_SEARCH === $zone['type']
                    ? $this->searchView($zone, $locale)
                    : null,
                'comments' => GridNormalizer::ZONE_COMMENTS === $zone['type']
                    ? $this->commentsView($currentPostId, $locale)
                    : null,
                'deck' => GridNormalizer::ZONE_DECK === $zone['type']
                    ? $this->deckView($zone['deckId'])
                    : null,
                'shared' => GridNormalizer::ZONE_SHARED === $zone['type']
                    ? $this->sharedView($posts[$zone['postId']] ?? null, $locale, $depth)
                    : null,
                'compare' => GridNormalizer::ZONE_COMPARE === $zone['type']
                    ? $this->compareView($zone, $held, $documents)
                    : null,
                'gallery' => match (true) {
                    GridNormalizer::ZONE_GALLERY === $zone['type'] => $this->galleryView($zone, $documents),
                    // The editor arranges a wall of films with the gallery's own
                    // controls - add, remove, reorder - so it is handed the
                    // films in the gallery's shape. The page reads `videoWall`.
                    GridNormalizer::ZONE_VIDEO_WALL === $zone['type'] && $forEditor => ['items' => array_map(
                        fn (int $id): array => ['url' => $this->videoFile($documents[$id] ?? null)['url'] ?? null],
                        $zone['mediaIds'],
                    )],
                    GridNormalizer::ZONE_TRAVEL_MAP === $zone['type'] && $forEditor => ['items' => array_map(
                        fn (int $id): array => ['url' => $this->mediaData($documents[$id] ?? null, '')['url'] ?? null],
                        $zone['mediaIds'],
                    )],
                    default => null,
                },
                'map' => GridNormalizer::ZONE_MAP === $zone['type']
                    ? $this->mapView($held['label'], $held['caption'], $documents[$zone['mediaId']] ?? null)
                    : null,
                'terms' => GridNormalizer::ZONE_TERMS === $zone['type']
                    ? $this->termsView($zone, $locale)
                    : null,
                'form' => GridNormalizer::ZONE_FORM === $zone['type']
                    ? $this->formView($zone['formId'], $locale)
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
                        fn (int $id): ?array => $this->videoFile($documents[$id] ?? null),
                        $zone['mediaIds'],
                    )))
                    : null,
                'activityFeed' => GridNormalizer::ZONE_ACTIVITY_FEED === $zone['type']
                    ? $this->activityFeedView($zone, $locale, $currentPostId)
                    : null,
                'travelMap' => GridNormalizer::ZONE_TRAVEL_MAP === $zone['type']
                    ? $this->travelMapView($zone, $held, $documents)
                    : null,
                'instagramFeed' => GridNormalizer::ZONE_INSTAGRAM_FEED === $zone['type']
                    ? $this->integrationViews->instagramFeed($zone['options'])
                    : null,
                'googleReviews' => GridNormalizer::ZONE_GOOGLE_REVIEWS === $zone['type']
                    ? $this->integrationViews->googleReviews($locale)
                    : null,
                'widget' => $this->widgetViews->build($zone, $held, $locale, fn (?int $id): ?array => null === $id ? null : $this->mediaData($documents[$id] ?? null, $held['alt']), $currentPostId),
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

        foreach ($zones as $index => $zone) {
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

        foreach ($zones as $index => $zone) {
            if (GridNormalizer::ZONE_TOC === $zone['type']) {
                $zones[$index]['toc'] = $headings;
            }
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
     * A form, ready for the same Vue component its own page mounts.
     *
     * Null on every "no" - no form named, none found, switched off, or not
     * translated here - so the template leaves the zone out rather than
     * drawing an empty box. An inactive form is a draft the site has not
     * published: the page it would have had 404s, and a zone should not be a
     * way around that.
     *
     * @return array{title: string, description: string|null, data: array<string, mixed>, submitPath: string}|null
     */
    private function formView(?int $formId, string $locale): ?array
    {
        if (null === $formId) {
            return null;
        }

        $form = $this->formRepository->find($formId);
        if (!$form instanceof FormInterface || !$form->isActive()) {
            return null;
        }

        $translation = $form->getTranslation($locale);
        if (!$translation instanceof FormTranslationInterface) {
            return null;
        }

        return [
            'title' => $translation->getTitle(),
            'description' => $translation->getDescription(),
            'data' => $this->formSerializer->serializeForReader($form, $locale),
            // The same route the form's own page posts to, so one endpoint
            // answers wherever the form is drawn - and its rate limit and its
            // validation come along unchanged.
            'submitPath' => $this->urlGenerator->generate('editorial_form_submit', [
                'locale' => $locale,
                'slug' => $translation->getSlug(),
            ]),
        ];
    }

    /**
     * A list zone, answered from the database on every render.
     *
     * One query per zone. A page holding three of them makes three, which is
     * the price of a list that is never out of date - the alternative is an
     * author remembering to edit a page every time they publish.
     *
     * @return array{columns: int, variant: string, cards: list<array<string, mixed>>}
     */
    private function postListView(array $zone, string $locale, ?int $currentPostId): array
    {
        if ('index' === ($zone['options']['listLayout'] ?? 'cards')) {
            return $this->postIndexView($zone, $locale, $currentPostId);
        }

        $posts = $this->postRepository->findLatestPublished(
            $locale,
            (int) $zone['limit'],
            $zone['postTypeId'],
            $zone['termId'],
            // A publication listing its neighbours should not offer itself
            // among them.
            $currentPostId,
        );

        $cards = [];
        foreach ($posts as $post) {
            $card = $this->postCard($post, $locale);
            if (null !== $card) {
                $cards[] = $card;
            }
        }

        return [
            'columns' => (int) $zone['columns'],
            'variant' => (string) $zone['cardVariant'],
            'cards' => $cards,
        ];
    }

    /**
     * The newest publications, and the latest releases of the repositories the
     * zone names when GitHub is switched on, in one list by date.
     *
     * Two sources and one order, because a reader of "what moved lately" does
     * not care which module a change came from - only that it is recent.
     *
     * @param array<string, mixed> $zone
     *
     * @return list<array{kind: string, title: string, url: string, date: string, dateLabel: string, detail: string}>
     */
    private function activityFeedView(array $zone, string $locale, ?int $currentPostId): array
    {
        $dates = new IntlDateFormatter($locale, IntlDateFormatter::LONG, IntlDateFormatter::NONE);
        $entries = [];

        foreach ($this->postRepository->findLatestPublished($locale, (int) $zone['limit'], $zone['postTypeId'], null, $currentPostId) as $post) {
            $card = $this->postCard($post, $locale);
            $published = $post->getPublishedAt();
            if (null === $card) {
                continue;
            }

            if (null === $published) {
                continue;
            }

            $entries[] = [
                'kind' => 'post',
                'title' => (string) $card['title'],
                'url' => $this->urlGenerator->generate('editorial_post', ['locale' => $locale, 'postTypeSlug' => $card['postTypeSlug'], 'slug' => $card['slug']]),
                'date' => $published->format(DATE_ATOM),
                'dateLabel' => (string) $dates->format($published),
                'detail' => (string) ($card['description'] ?? ''),
            ];
        }

        if ($zone['options']['feedGithub'] ?? false) {
            foreach ($this->gitHubActivityView->build($locale, ['githubMode' => 'releases', 'githubRepos' => $zone['options']['githubRepos'] ?? []])['releases'] ?? [] as $release) {
                if ('' === $release['publishedAt']) {
                    continue;
                }

                $entries[] = [
                    'kind' => 'release',
                    'title' => $release['repo'].' '.$release['name'],
                    'url' => $release['url'],
                    'date' => new DateTimeImmutable($release['publishedAt'])->format(DATE_ATOM),
                    'dateLabel' => $release['dateLabel'],
                    'detail' => $release['summary'],
                ];
            }
        }

        usort($entries, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        return array_slice($entries, 0, max(1, (int) $zone['limit']));
    }

    /**
     * Every publication the list would show, by letter, for an index.
     *
     * The count is not the zone's `limit`: an index that stops at twelve is
     * not an index. It stops at INDEX_LIMIT instead, which is a documentation
     * or a glossary of a good size, and past which a page of links wants a
     * search rather than a longer page.
     *
     * Letters are read off the title with its accents removed, so « Écran »
     * files under E; anything that does not start with a letter files under #.
     *
     * @param array<string, mixed> $zone
     *
     * @return array{columns: int, variant: string, cards: list<array<string, mixed>>, index: list<array{letter: string, entries: list<array{title: string, url: string}>}>}
     */
    private function postIndexView(array $zone, string $locale, ?int $currentPostId): array
    {
        $posts = $this->postRepository->findLatestPublished($locale, self::INDEX_LIMIT, $zone['postTypeId'], $zone['termId'], $currentPostId);
        $collator = new Collator($locale);
        $entries = [];

        foreach ($posts as $post) {
            $card = $this->postCard($post, $locale);

            if (null !== $card && '' !== (string) $card['title']) {
                $entries[] = [
                    'title' => (string) $card['title'],
                    'url' => $this->urlGenerator->generate('editorial_post', [
                        'locale' => $locale,
                        'postTypeSlug' => $card['postTypeSlug'],
                        'slug' => $card['slug'],
                    ]),
                ];
            }
        }

        usort($entries, static fn (array $a, array $b): int => (int) $collator->compare($a['title'], $b['title']));

        $groups = [];
        foreach ($entries as $entry) {
            $first = mb_strtoupper(mb_substr((string) transliterator_transliterate('Any-Latin; Latin-ASCII', $entry['title']), 0, 1));
            $letter = 1 === preg_match('/^[A-Z]$/', $first) ? $first : '#';
            $groups[$letter][] = $entry;
        }

        // `#` last, the way a printed index puts figures after Z.
        uksort($groups, static fn (string $a, string $b): int => ('#' === $a) <=> ('#' === $b) ?: $a <=> $b);

        $index = [];
        foreach ($groups as $letter => $list) {
            $index[] = ['letter' => $letter, 'entries' => $list];
        }

        return [
            'columns' => (int) $zone['columns'],
            'variant' => (string) $zone['cardVariant'],
            'cards' => [],
            'index' => $index,
        ];
    }

    /**
     * The panels of a tabs zone, each with its label and its body.
     *
     * The body goes through the same renderer a text zone's does, so a panel
     * is sanitised on exactly the path everything else already takes - there
     * is no second way into the markup here.
     *
     * A panel with no label and nothing written is dropped, for the reason a
     * blank item entry is: a row typed into tomorrow belongs in the editor and
     * not on the page. The editor keeps them, which is what `forEditor` is for.
     *
     * Ids come from the stored list rather than from a counter, because that
     * is what the labels and the panels are tied together by in the markup -
     * and a page with two tab zones must not have them fighting over `panel-1`.
     *
     * @param array<string, mixed> $zone
     * @param array<string, mixed> $held
     *
     * @return array{zoneId: string, panels: list<array{id: string, label: string, html: string}>}
     */
    private function tabsView(array $zone, array $held, string $locale, bool $forEditor): array
    {
        $texts = is_array($held['items'] ?? null) ? $held['items'] : [];
        $panels = [];

        foreach (is_array($zone['items'] ?? null) ? $zone['items'] : [] as $panel) {
            $id = $panel['id'] ?? null;

            if (!is_string($id)) {
                continue;
            }

            $words = is_array($texts[$id] ?? null) ? $texts[$id] : [];
            $label = (string) ($words['title'] ?? '');
            $html = $this->blocksRenderer->render(
                is_array($words['blocks'] ?? null) ? $words['blocks'] : [],
                $locale,
            );

            if (!$forEditor && '' === $label && '' === mb_trim(strip_tags($html))) {
                continue;
            }

            $panels[] = ['id' => $id, 'label' => $label, 'html' => $html];
        }

        return ['zoneId' => (string) $zone['id'], 'panels' => $panels];
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
     * Where a search field posts, and what it is allowed to find.
     *
     * The endpoint is the one the sequence search already uses, and so is the
     * component that draws it: a second search built for this zone would be a
     * second set of empty states, a second debounce and a second thing to keep
     * in step.
     *
     * An empty type means the whole site, which is the answer that needs no
     * setting up.
     *
     * @param array<string, mixed> $zone
     *
     * @return array{searchUrl: string}
     */
    private function searchView(array $zone, string $locale): array
    {
        $parameters = ['locale' => $locale];

        $postType = null === $zone['postTypeId']
            ? null
            : $this->postTypeRepository->find($zone['postTypeId']);

        if ($postType instanceof PostTypeInterface) {
            $parameters['type'] = $postType->getSlug();
        }

        return ['searchUrl' => $this->urlGenerator->generate('editorial_home_search', $parameters)];
    }

    /**
     * The three addresses the comment thread needs, for the page it sits on.
     *
     * Built from the current publication rather than from anything on the
     * zone: a thread belongs to the page it is drawn on, and offering an
     * author a choice there would be offering them a way to put one page's
     * replies under another.
     *
     * @return array{listPath: string, submitPath: string, reactPathTemplate: string}|null
     */
    private function commentsView(?int $currentPostId, string $locale): ?array
    {
        // Its own lookup rather than the shared prefetch: the prefetch gathers
        // what zones *name*, and this zone names nothing - it is about the
        // page it stands on. One query, and only on a page carrying the zone.
        $post = null === $currentPostId ? null : $this->postRepository->find($currentPostId);

        if (!$post instanceof PostInterface) {
            return null;
        }

        $translation = $post->getTranslation($locale);
        $typeSlug = $post->getPostType()->getSlug();

        if (!$translation instanceof PostTranslationInterface || '' === $translation->getSlug()) {
            return null;
        }

        $parameters = ['locale' => $locale, 'postTypeSlug' => $typeSlug, 'slug' => $translation->getSlug()];

        return [
            'listPath' => $this->urlGenerator->generate('editorial_post_comments', $parameters),
            'submitPath' => $this->urlGenerator->generate('editorial_post_comment_submit', $parameters),
            'reactPathTemplate' => $this->urlGenerator->generate(
                'editorial_comment_react',
                [...$parameters, 'commentId' => '__commentId__'],
            ),
        ];
    }

    /**
     * A presentation, but only one somebody has actually published.
     *
     * A deck is an internal document until a share link exists for it, so a
     * zone naming one with no live link draws nothing. A revoked link, an
     * expired one and one behind a password are all "no": the last because a
     * page cannot ask for a password on the deck's behalf, and an iframe onto
     * the unlock form would be a locked door drawn inside an article.
     *
     * Same origin, so nothing here loads a third party - this is the site
     * showing its own page inside its own page.
     *
     * @return array{url: string, title: string}|null
     */
    private function deckView(?int $deckId): ?array
    {
        if (null === $deckId) {
            return null;
        }

        $deck = $this->deckRepository->find($deckId);

        if (!$deck instanceof DeckInterface) {
            return null;
        }

        $now = new DateTimeImmutable();

        foreach ($this->deckShareLinkRepository->findForDeck($deck) as $link) {
            if (null !== $link->getRevokedAt()) {
                continue;
            }

            if (null !== $link->getPasswordHash()) {
                continue;
            }

            $expiresAt = $link->getExpiresAt();

            if (null !== $expiresAt && $expiresAt < $now) {
                continue;
            }

            return [
                'url' => $this->urlGenerator->generate('public_deck_show', ['token' => $link->getToken()]),
                'title' => $deck->getTitle(),
            ];
        }

        return null;
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
     * Before and after, or nothing.
     *
     * Both or neither, decided here rather than by the template: one picture
     * of a pair is not a comparison, and a handle with nothing on its right is
     * a control that lies about what it does. The same reasoning the button
     * zone applies to its label and its address.
     *
     * The words under each side are translated, and the template supplies a
     * default when the author typed none: "before" and "after" are what they
     * say in nine cases out of ten, and asking every time is asking for
     * nothing.
     *
     * @param array<string, mixed>          $zone
     * @param array<string, mixed>          $held
     * @param array<int, DocumentInterface> $documents
     *
     * @return array{before: array<string, mixed>, after: array<string, mixed>, beforeLabel: string, afterLabel: string}|null
     */
    private function compareView(array $zone, array $held, array $documents): ?array
    {
        $ids = is_array($zone['mediaIds'] ?? null) ? $zone['mediaIds'] : [];

        if (2 !== count($ids)) {
            return null;
        }

        $before = $this->mediaData($documents[$ids[0]] ?? null, '');
        $after = $this->mediaData($documents[$ids[1]] ?? null, '');

        if (null === $before || null === $after) {
            return null;
        }

        return [
            'before' => $before,
            'after' => $after,
            // `alt` and `label` rather than two fields of their own: the two
            // spare translated slots a zone already carries, used for the two
            // words this one needs. Empty when the author typed nothing, and
            // the template falls back to a translated default - this class has
            // no translator, and a French word hard-coded here would be a
            // French word on an English page.
            'beforeLabel' => (string) $held['alt'],
            'afterLabel' => (string) $held['label'],
        ];
    }

    /**
     * The pictures of a gallery zone, resolved against the one prefetch.
     *
     * A document named here but since deleted, or replaced by something that
     * is not a picture, drops out rather than leaving a hole: {@see mediaData}
     * already answers that question and this only has to respect the answer.
     *
     * `ratioStyle` is empty when the zone asks for its own proportions, and
     * that emptiness is what the template reads to flow the pictures down
     * columns instead of cropping them into a grid.
     *
     * @param array<string, mixed>          $zone
     * @param array<int, DocumentInterface> $documents
     *
     * @return array{columns: int, ratioStyle: string, items: list<array<string, mixed>>}
     */
    private function galleryView(array $zone, array $documents): array
    {
        $items = [];

        foreach (is_array($zone['mediaIds'] ?? null) ? $zone['mediaIds'] : [] as $id) {
            $picture = $this->mediaData($documents[$id] ?? null, '');

            if (null !== $picture) {
                $items[] = $picture;
            }
        }

        return [
            'columns' => (int) $zone['columns'],
            'ratioStyle' => $this->ratioStyle($zone['ratio']),
            'items' => $items,
        ];
    }

    /**
     * An address, and a way to be taken to it.
     *
     * Nothing here reaches a provider while the page is being read: the
     * address is text the author typed, the picture is one they chose, and the
     * link is only followed if the reader decides to. That is the whole design
     * of this zone - a draggable map would be a third party on every view,
     * chosen once by us for every client.
     *
     * The link goes to Google Maps' universal address, which is what opens the
     * native application on Android and iOS and a page anywhere else. It is a
     * choice rather than a neutrality: OpenStreetMap would not profile anyone,
     * and would not open the application a reader already navigates with. One
     * line to change here if the trade is judged the other way.
     *
     * @param string|null $name  what the place is called, in this language
     * @param string|null $lines the address as typed, one line per line
     *
     * @return array{name: string, lines: list<string>, directionsUrl: string, media: array<string, mixed>|null}|null
     */
    private function mapView(?string $name, ?string $lines, ?DocumentInterface $media): ?array
    {
        $address = [];
        foreach (explode("\n", (string) $lines) as $line) {
            $line = mb_trim($line);

            if ('' !== $line) {
                $address[] = $line;
            }
        }

        // A zone with no address is not a place, whatever else it carries. A
        // name and a photograph alone would draw a card that cannot answer the
        // one question it is there for.
        if ([] === $address) {
            return null;
        }

        return [
            'name' => (string) $name,
            'lines' => $address,
            // Joined by commas rather than by the newlines it was typed with:
            // a query string carrying line breaks is a query string that has
            // to be repaired at the other end.
            'directionsUrl' => 'https://www.google.com/maps/search/?api=1&query='
                .rawurlencode(implode(', ', $address)),
            'media' => $this->mediaData($media, ''),
        ];
    }

    /**
     * The terms of one taxonomy, in the order the backend arranges them.
     *
     * One query per zone, like the list beside it, and for the same reason: a
     * page that answers the question on every render is a page nobody has to
     * remember to edit.
     *
     * A term with nothing written in this language is dropped rather than
     * shown under its slug. A word an author never wrote is not a word to put
     * in front of a reader, and a link labelled with a slug reads as a fault.
     *
     * @param array<string, mixed> $zone
     *
     * @return array{name: string, entries: list<array{label: string, url: string}>}
     */
    private function termsView(array $zone, string $locale): array
    {
        $taxonomy = null === $zone['taxonomyId']
            ? null
            : $this->taxonomyRepository->find($zone['taxonomyId']);

        if (!$taxonomy instanceof TaxonomyInterface) {
            return ['name' => '', 'entries' => []];
        }

        $entries = [];
        foreach ($this->taxonomyTermRepository->findByTaxonomyOrdered($taxonomy) as $term) {
            $translation = $term->getTranslation($locale);
            if (!$translation instanceof TaxonomyTermTranslationInterface) {
                continue;
            }

            if ('' === $translation->getName()) {
                continue;
            }

            $entries[] = [
                'label' => $translation->getName(),
                'url' => $this->urlGenerator->generate('editorial_term', [
                    'locale' => $locale,
                    'taxonomySlug' => $taxonomy->getSlug(),
                    'termSlug' => $translation->getSlug(),
                ]),
            ];
        }

        return [
            // The taxonomy's own name in this language, for a zone that wants
            // to say what it is listing. Empty when untranslated, and the
            // template draws no heading rather than an empty one.
            'name' => $taxonomy->getTranslation($locale)?->getLabel() ?? '',
            'entries' => $entries,
        ];
    }

    /**
     * An item list, joined back together: the arrangement says how many
     * entries there are and which picture each carries, the translation says
     * what they read.
     *
     * An entry whose words are all empty is dropped. A list is authored by
     * adding rows and filling them in, so the blank one at the end is the one
     * being written - it belongs in the editor, not on the page.
     *
     * @param array<string, mixed>          $zone
     * @param array<string, mixed>          $held      this locale's content for the zone
     * @param array<int, DocumentInterface> $documents
     *
     * @return array{display: string, columns: int, entries: list<array<string, mixed>>}
     */
    private function itemsView(array $zone, array $held, array $documents): array
    {
        $texts = is_array($held['items'] ?? null) ? $held['items'] : [];
        $entries = [];

        foreach (is_array($zone['items'] ?? null) ? $zone['items'] : [] as $item) {
            $id = $item['id'] ?? null;
            if (!is_string($id)) {
                continue;
            }

            $words = is_array($texts[$id] ?? null) ? $texts[$id] : [];
            $media = $this->mediaData($documents[$item['mediaId']] ?? null, '');

            $title = (string) ($words['title'] ?? '');
            $description = (string) ($words['description'] ?? '');
            $caption = (string) ($words['caption'] ?? '');

            if ('' === $title && '' === $description && '' === $caption && null === $media) {
                continue;
            }

            $entries[] = [
                'id' => $id,
                'title' => $title,
                'description' => $description,
                'caption' => $caption,
                'url' => $words['url'] ?? null,
                'media' => $media,
                // Only the offers costume draws it, but it travels with every
                // entry: reading it in the template is one `default`, and
                // deciding here which costumes may carry it would put the
                // costume's business in the wrong file.
                'featured' => (bool) ($item['featured'] ?? false),
                // 1-based, for the display that numbers its steps. Worked out
                // here rather than in the template, which would have to count
                // the entries it skipped.
                'position' => count($entries) + 1,
            ];
        }

        return [
            'display' => (string) $zone['display'],
            'columns' => (int) $zone['columns'],
            'entries' => $entries,
            // Carried into the view rather than read off the zone in Twig,
            // because `_grid_items` is handed `items` and nothing else - and
            // giving it the whole zone to reach one flag would hand it the
            // span, the surface and the anchor as well.
            'exclusiveOpen' => (bool) ($zone['exclusiveOpen'] ?? false),
            // The grouping name the browser folds on. Per zone, so two lists
            // on one page do not close each other's panels; `id` is already
            // unique across the grid, stacks included.
            'id' => (string) $zone['id'],
        ];
    }

    /**
     * The same entries, in the shape the editor keeps them in.
     *
     * Identity and order exactly as stored - the ids are what each entry's
     * words are filed under, so an arrangement that comes back without them
     * comes back as different entries - plus the picture resolved, so the
     * picker shows the logo it already holds rather than a number.
     *
     * Blank entries are kept, unlike the page's view: a row typed into
     * tomorrow is a row today.
     *
     * @param array<string, mixed>          $zone
     * @param array<int, DocumentInterface> $documents
     *
     * @return list<array{id: string, mediaId: int|null, media: array<string, mixed>|null}>
     */
    private function itemsForEditor(array $zone, array $documents): array
    {
        $items = [];

        foreach (is_array($zone['items'] ?? null) ? $zone['items'] : [] as $item) {
            $id = $item['id'] ?? null;
            if (!is_string($id)) {
                continue;
            }

            $mediaId = $item['mediaId'] ?? null;

            $items[] = [
                'id' => $id,
                'mediaId' => is_int($mediaId) ? $mediaId : null,
                'media' => $this->mediaData($documents[$mediaId] ?? null, ''),
            ];
        }

        return $items;
    }

    /**
     * A trip's stops, paired by position with the gallery of photos the
     * author picked - the same pairing a compare zone makes between its two
     * slots, extended to as many as there are.
     *
     * A line the parser cannot read (not exactly three parts, or a latitude
     * or longitude that is not a plain number) is dropped rather than
     * guessed: a pin planted at 0°N 0°E from a typo is worse than a pin
     * missing.
     *
     * @param array<string, mixed>          $zone
     * @param array<string, mixed>          $held
     * @param array<int, DocumentInterface> $documents
     *
     * @return array{stops: list<array<string, mixed>>}|null
     */
    private function travelMapView(array $zone, array $held, array $documents): ?array
    {
        $stops = [];

        foreach (explode("\n", (string) $held['code']) as $index => $line) {
            $parts = array_map(trim(...), explode('|', $line));
            if (3 !== count($parts)) {
                continue;
            }

            if (!is_numeric($parts[1])) {
                continue;
            }

            if (!is_numeric($parts[2])) {
                continue;
            }

            if ('' === $parts[0]) {
                continue;
            }

            $lat = (float) $parts[1];
            $lng = (float) $parts[2];
            if ($lat < -90) {
                continue;
            }

            if ($lat > 90) {
                continue;
            }

            if ($lng < -180) {
                continue;
            }

            if ($lng > 180) {
                continue;
            }

            $mediaId = $zone['mediaIds'][$index] ?? null;
            $photo = null !== $mediaId ? $this->mediaData($documents[$mediaId] ?? null, $parts[0]) : null;

            $stops[] = [
                'label' => $parts[0],
                'lat' => $lat,
                'lng' => $lng,
                'photo' => $photo,
            ];
        }

        return [] === $stops ? null : ['stops' => $stops];
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
        foreach ($this->postRepository->findBy(['id' => $ids]) as $post) {
            $posts[(int) $post->getId()] = $post;
        }

        return $posts;
    }

    /**
     * A linked publication is shown in the language of the page it appears on,
     * which is why the id is shared and this is not: the post carries its own
     * translations and picking the right one is the renderer's job.
     *
     * Built here rather than through PostSerializer, for two reasons. It would
     * be a circular dependency - the serialiser calls this builder to hand the
     * editor a resolved layout. And `serializeCard` computes terms and custom
     * fields, which cost queries and which a grid card does not show: six
     * fields is the whole of it.
     *
     * @return array<string, mixed>|null null when the post is gone, trashed,
     *                                   or has nothing written in this locale
     */
    private function postCard(?PostInterface $post, string $locale): ?array
    {
        if (!$post instanceof PostInterface || $post->isTrashed()) {
            return null;
        }

        $translation = $post->getTranslation($locale);
        $thumbnail = $this->thumbnailPresenter->present($post);

        // A card with no title and no address is a link to nowhere. That is
        // what an untranslated publication looks like, and it should leave a
        // gap rather than an empty box.
        if (null === $translation?->getTitle() || null === $translation->getSlug()) {
            return null;
        }

        return [
            'id' => $post->getId(),
            'title' => $translation->getTitle(),
            'slug' => $translation->getSlug(),
            // The description, never the meta description: that one is written
            // for a search snippet and cut around 160 characters.
            'description' => $translation->getDescription(),
            'postTypeSlug' => $post->getPostType()->getSlug(),
            // Named the same way serializeCard names them, so one card
            // partial can read either shape. Spreading the presenter's own
            // keys would have put a `url` on a card, which reads as the
            // publication's address rather than its picture's.
            'thumbnailUrl' => $thumbnail['url'],
            'thumbnailFitClass' => $thumbnail['objectFitClass'],
            'thumbnailFocalPosition' => $thumbnail['focalPosition'],
        ];
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
     * The crop, as a declaration rather than a class.
     *
     * A Tailwind class would have to be written out somewhere Tailwind reads -
     * `aspect-video` happens to appear in this module's Twig, but
     * `aspect-square` and `aspect-[3/4]` appear nowhere, so choosing them here
     * would emit nothing and the crop would silently not happen. The project
     * already answered this question for spans, which go out as custom
     * properties for the same reason. `ThumbnailFitEnum::objectFitClass()`
     * returns classes from PHP and gets away with it only because those strings
     * exist in unrelated Vue files.
     *
     * Empty for `natural`, so the caller can test it and the style attribute
     * stays clean.
     */
    private function ratioStyle(string $ratio): string
    {
        return match ($ratio) {
            '16x9' => 'aspect-ratio: 16 / 9;',
            '4x3' => 'aspect-ratio: 4 / 3;',
            '1x1' => 'aspect-ratio: 1 / 1;',
            '3x4' => 'aspect-ratio: 3 / 4;',
            // `fill` and `natural` both land here: neither states a ratio. What
            // separates them is a height, which is a class on the element
            // rather than a declaration - see `_grid_zone.html.twig`.
            default => '',
        };
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
     * A video the library holds, ready for a `<video>`.
     *
     * The mime is checked rather than trusted: the picker offers videos, but a
     * fixture, an API write or a file replaced after the zone was configured
     * all reach past it - and a player pointed at a PDF is a black rectangle
     * with nothing said anywhere. Same reasoning as {@see mediaData}, and the
     * same place to ask it: only the render knows what the file is today.
     *
     * @return array{url: string, mimeType: string, poster: string|null, width: int|null, height: int|null}|null
     */
    private function videoFile(?DocumentInterface $media): ?array
    {
        if (!$media instanceof DocumentInterface) {
            return null;
        }

        $mime = MimeTypeEnum::tryFrom((string) $media->getMimeType());

        if (!$mime?->isVideo()) {
            return null;
        }

        $url = $this->documentUrlGenerator->publicUrl($media);

        if (null === $url) {
            return null;
        }

        return [
            'url' => $url,
            'mimeType' => $mime->value,
            // The still the player shows before anything is downloaded.
            'poster' => $this->documentUrlGenerator->thumbnailPathUrl($media),
            // The film's own pixel size, so the box is the right shape before
            // a single byte is fetched. Without it a `preload="none"` player
            // falls back to the browser's 300x150 default, which is why an
            // unplayed portrait film used to render as a squat black
            // rectangle. Null when the document predates the column.
            'width' => $media->getWidth(),
            'height' => $media->getHeight(),
        ];
    }

    /**
     * A recording the library holds, for a player the browser draws itself.
     *
     * Asked at render for the reason {@see videoFile} is: a zone configured
     * with a recording stays configured with it after the file behind it is
     * replaced by a spreadsheet, and only the render knows what it is today.
     * A player pointed at the wrong thing is a silent control that does
     * nothing, with no message anywhere.
     *
     * No poster and no dimensions, unlike a film: a `<audio>` element has a
     * height of its own that owes nothing to what it plays.
     *
     * @return array{url: string, mimeType: string}|null
     */
    private function audioFile(?DocumentInterface $media): ?array
    {
        if (!$media instanceof DocumentInterface) {
            return null;
        }

        if (!MimeGroupEnum::Audio->matches($media->getMimeType())) {
            return null;
        }

        // Published only, for the reason {@see documentCard} gives, and it
        // bites harder here. Since `/uploads` began withholding anything not
        // published, {@see DocumentUrlGenerator::publicUrl} hands back the
        // backend address for a draft - so a zone naming one would draw a
        // player that answers 403 to every visitor, silently. A picture in
        // that state at least shows a broken image; a dead player shows
        // nothing at all and reads as a site that does not work.
        if (DocumentStatusEnum::Published !== $media->getStatus()) {
            return null;
        }

        $url = $this->documentUrlGenerator->publicUrl($media);

        if (null === $url) {
            return null;
        }

        return [
            'url' => $url,
            'mimeType' => (string) $media->getMimeType(),
        ];
    }

    /**
     * A file offered for download, as the card that describes it.
     *
     * **Published only.** A library holds a client's internal papers beside
     * the ones they hand out, and the status column is what already tells them
     * apart; a draft named in a zone renders as nothing rather than as a link.
     * That is the whole of the check, and it is worth being plain about what it
     * is not: the file itself is served by a public route, so this decides what
     * a page *advertises*, not what the server will hand over to somebody who
     * already has the address.
     *
     * The extension comes off the original name rather than off the mime type:
     * it is what the reader will see in their downloads folder, and `xlsx` says
     * more to them than `application/vnd.openxmlformats-officedocument…` ever
     * will.
     *
     * @param string|null $label what the control says, in the page's language;
     *                           the document's own title when nothing is typed
     *
     * @return array{title: string, url: string, extension: string, size: int|null}|null
     */
    private function documentCard(?DocumentInterface $media, ?string $label): ?array
    {
        if (!$media instanceof DocumentInterface) {
            return null;
        }

        if (DocumentStatusEnum::Published !== $media->getStatus()) {
            return null;
        }

        $url = $this->documentUrlGenerator->publicUrl($media);

        if (null === $url) {
            return null;
        }

        $extension = pathinfo((string) $media->getOriginalName(), PATHINFO_EXTENSION);

        return [
            'title' => null !== $label && '' !== $label ? $label : $media->getTitle(),
            'url' => $url,
            'extension' => mb_strtoupper($extension),
            'size' => $media->getSize(),
        ];
    }

    private function mediaData(?DocumentInterface $media, string $alt, ?string $url = null): ?array
    {
        // The library wins whenever it has an answer: a document carries a
        // focal point, a variant sized for this slot and an alt of its own,
        // and none of that can be read off an address. The address is what an
        // author has while a page is being drafted, not a second way of doing
        // the same thing.
        if (!$media instanceof DocumentInterface) {
            return null === $url ? null : [
                'url' => $url,
                'alt' => $alt,
                // Nothing to focus on: an address says where a picture is, not
                // what matters inside it. Centre is what `object-cover` does
                // without instruction anyway, and stating it keeps the template
                // free of a second branch.
                'focalPosition' => '50% 50%',
            ];
        }

        // A media zone renders an `<img>`, so what it holds has to be an
        // image. The backend picker only ever offers those, but three paths
        // reach past it - a fixture, an API write, and a document whose file
        // is replaced after the zone was configured - and an `<img>` pointed
        // at an mp4 is a broken image with nothing said anywhere.
        //
        // Asked here rather than refused in `GridNormalizer` for two reasons.
        // The normaliser has no database and runs on every render, not only on
        // the way in - giving it a repository would put a query behind every
        // page view. And the third path above has no write to refuse: a layout
        // that was valid the day it was saved stops being valid the day the
        // file behind it changes. Only the render knows.
        if (!MimeGroupEnum::Image->matches($media->getMimeType())) {
            return null;
        }

        $url = $this->documentUrlGenerator->variantUrl($media, 'large')
            ?? $this->documentUrlGenerator->publicUrl($media);

        // A document can carry no file at all - the demo library keeps three
        // that way on purpose, so the upload flow has something to be tested
        // against. Without this the zone emitted `<img src="">`, which is a
        // broken image rather than an absent one.
        if (null === $url) {
            return null;
        }

        return [
            'url' => $url,
            // The zone's own alt wins: the same picture can mean different
            // things in two places, and the document's alt describes the file.
            'alt' => '' !== $alt ? $alt : (string) $media->getAlt(),
            'focalPosition' => $this->documentUrlGenerator->focalPositionCss($media),
            // The camera line: « Canon EOS R6 · f/2.8 · 1/500 s · ISO 100 ».
            // Empty for a picture uploaded before its settings were read, or
            // one whose camera wrote nothing.
            'exif' => implode(' · ', array_values(array_intersect_key(
                $media->getExif(),
                array_flip(['camera', 'lens', 'focal', 'aperture', 'shutter', 'iso']),
            ))),
            // **Ce qui réserve la place avant que l'image arrive.** Sans les
            // deux, un `<img>` en chargement différé occupe zéro pixel de
            // haut : la page est courte, puis s'allonge à chaque image qui
            // se pose, et on voit le contenu descendre par à-coups. Mesuré
            // sur la page photographie, où trois images font toute la page.
            //
            // Ce sont les dimensions du document et l'adresse est celle
            // d'une variante, ce qui est sans importance : le navigateur
            // n'en tire qu'un rapport, et une variante est un
            // redimensionnement. Vérifié sur la production, où la variante
            // « large » d'une photo mesure exactement ce que le document
            // déclare.
            'width' => $media->getWidth(),
            'height' => $media->getHeight(),
            // Null for anything we host ourselves. Present, and displayed by
            // the template, for a stock photo whose licence requires it.
            'credit' => $this->creditPresenter->present($media),
        ];
    }

    /**
     * A `custom` surface's colour, gradient or picture, ready for the
     * template - mirrors {@see BannerViewBuilder::fillStyle()} and its own
     * `mediaData`, one call site rather than two renderers.
     *
     * @param array<string, mixed>          $background a normalised zone background
     * @param array<int, DocumentInterface> $documents  every document this render already fetched
     *
     * @return array{fillStyle: ?string, media: ?array<string, mixed>, overlay: int}
     */
    private function zoneBackgroundView(array $background, array $documents): array
    {
        return [
            'fillStyle' => match ($background['type']) {
                GridNormalizer::ZONE_FILL_SOLID => null !== $background['color']
                    ? sprintf('background-color: %s;', $background['color'])
                    : null,
                GridNormalizer::ZONE_FILL_GRADIENT => null !== $background['gradientFrom'] && null !== $background['gradientTo']
                    ? sprintf(
                        'background-image: linear-gradient(%ddeg, %s, %s);',
                        $background['gradientAngle'],
                        $background['gradientFrom'],
                        $background['gradientTo'],
                    )
                    : null,
                default => null,
            },
            'media' => $this->mediaData($documents[$background['mediaId']] ?? null, ''),
            // Reuses the dedicated video zone's own resolver: the mime is
            // checked here rather than trusted from the layout, the same
            // reasoning as there - a file can be replaced after the zone was
            // configured.
            'video' => $this->videoFile($documents[$background['videoId']] ?? null),
            'overlay' => $background['overlay'],
        ];
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
