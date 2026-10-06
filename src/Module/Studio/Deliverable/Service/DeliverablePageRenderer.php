<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Service;

use Aurora\Core\Frontend\Service\Context;
use Aurora\Module\Configuration\Setting\Service\SiteTimezone;
use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Editorial\Post\Grid\GridSlides;
use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Module\Editorial\Post\Service\ReadingTimeCalculator;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use DateTimeImmutable;
use DateTimeInterface;
use IntlDateFormatter;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

use function array_intersect_key;
use function array_map;
use function count;
use function in_array;
use function is_array;
use function is_string;

/**
 * A deliverable's page, as the client reads it.
 *
 * The grid is resolved by the same builder as the site pages, and drawn by
 * the same zone template: a block placed in a deliverable looks exactly as it
 * does on the site. Around it, the reading template: no menu, no footer of
 * links, a header that says who the document was prepared for.
 *
 * Three readers, one rendering: the client from their space (with a way back
 * to the space), the recipient of a reading link, and the studio looking at
 * its work before opening it.
 */
final readonly class DeliverablePageRenderer
{
    /**
     * The zones that have no place in a document handed to a client.
     *
     * The ones that only live on a site page: a comment thread, a search, a
     * publication list, a form or a newsletter sign-up, a poll, an
     * appointment booking. They hang on a publication or on the site's
     * audience, which a deliverable does not have: each could only draw a
     * failing block.
     *
     * And the ones that would show what does not belong to the client: the
     * shared block renders another publication's content, drafts included,
     * and the GitHub activity, the Instagram feed and the Google reviews are
     * the studio's integration data, not its client's.
     */
    public const array HIDDEN_ZONE_TYPES = [
        GridNormalizer::ZONE_COMMENTS,
        GridNormalizer::ZONE_SEARCH,
        GridNormalizer::ZONE_POST,
        GridNormalizer::ZONE_POST_LIST,
        GridNormalizer::ZONE_TERMS,
        GridNormalizer::ZONE_DECK,
        GridNormalizer::ZONE_FORM,
        GridNormalizer::ZONE_POLL,
        GridNormalizer::ZONE_ACTIVITY_FEED,
        GridNormalizer::ZONE_NEWSLETTER_SIGNUP,
        GridNormalizer::ZONE_NEWSLETTER_PRIVACY,
        GridNormalizer::ZONE_APPOINTMENT_BOOKING,
        GridNormalizer::ZONE_SHARED,
        GridNormalizer::ZONE_GITHUB_ACTIVITY,
        GridNormalizer::ZONE_INSTAGRAM_FEED,
        GridNormalizer::ZONE_GOOGLE_REVIEWS,
    ];

    /**
     * The layout without the hidden zones, before any of them is resolved.
     *
     * It happens here, on the layout, and not on the built grid: a zone
     * removed afterwards has already been resolved, so it has already read
     * its publication or its deck. The editor previews go through here too,
     * so as not to show what the client's page will not show.
     *
     * @param array<string, mixed> $layout
     *
     * @return array<string, mixed>
     */
    public static function withoutHiddenLayoutZones(array $layout): array
    {
        if (is_array($layout['zones'] ?? null)) {
            $layout['zones'] = self::keepShownZones($layout['zones']);
        }

        return $layout;
    }

    /**
     * The content of the remaining zones only: the reading time and the
     * slide split do not count what the page does not show.
     *
     * @param array<string, mixed> $layout  the already filtered layout
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    public static function contentOfShownZones(array $layout, array $content): array
    {
        if (!is_array($content['zones'] ?? null) || !is_array($layout['zones'] ?? null)) {
            return $content;
        }

        $kept = [];
        foreach (GridNormalizer::flatten($layout['zones']) as $zone) {
            if (isset($zone['id']) && is_string($zone['id'])) {
                $kept[$zone['id']] = true;
            }
        }

        return [...$content, 'zones' => array_intersect_key($content['zones'], $kept)];
    }

    /**
     * @param array<int|string, mixed> $zones
     *
     * @return list<array<string, mixed>>
     */
    private static function keepShownZones(array $zones): array
    {
        $kept = [];

        foreach ($zones as $zone) {
            if (!is_array($zone)) {
                continue;
            }

            if (in_array($zone['type'] ?? null, self::HIDDEN_ZONE_TYPES, true)) {
                continue;
            }

            if (is_array($zone['children'] ?? null)) {
                $zone['children'] = self::keepShownZones($zone['children']);
            }

            $kept[] = $zone;
        }

        return $kept;
    }

    public function __construct(
        private Environment $twig,
        private Context $context,
        private ThemeContext $themeContext,
        private GridViewBuilder $gridViewBuilder,
        private ReadingTimeCalculator $readingTimeCalculator,
        private SiteTimezone $siteTimezone,
        private GridSlides $gridSlides,
    ) {}

    /**
     * @param string|null $backUrl          where the reader goes back to, when they come from a page of their own
     * @param bool        $markPlaceholders the [passages to replace] highlighted: the author's
     *                                      preview, never the client's page
     * @param bool        $print            the version to print as PDF: as slides, one per
     *                                      page, whatever display was chosen
     * @param string|null $view             the view the reader chose (`?view=`), among
     *                                      {@see DeliverableAppearance::DISPLAYS}; any other
     *                                      value, or none, keeps the author's
     */
    public function render(DeliverableInterface $deliverable, ?string $backUrl = null, bool $markPlaceholders = false, bool $print = false, ?string $view = null): Response
    {
        $locale = $deliverable->getLocale();

        $response = new Response($this->page(
            [
                'locale' => $locale,
                'title' => $deliverable->getTitle(),
                'summary' => $deliverable->getSummary(),
                'gridLayout' => $deliverable->getGridLayout(),
                'gridContent' => $deliverable->getGridContent(),
                'appearance' => $deliverable->getAppearance(),
                'readingHeader' => $deliverable->getReadingHeader(),
                'updatedAt' => $deliverable->getUpdatedAt(),
            ],
            $backUrl,
            $markPlaceholders,
            $print,
            view: $view,
        ));
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    /**
     * The page read by its recipient, through a reading link or the client's
     * space.
     *
     * `?print=1` is only honoured if the author allowed the PDF for this
     * deliverable (the "Autoriser le PDF au lecteur" setting, off by
     * default): otherwise the request is ignored and the page reads as usual.
     * It is not a guarded secret, the content is already on screen, but an
     * author's decision: a document you do not want circulating as a file
     * does not offer the button.
     *
     * @param string|null $backUrl where the reader goes back to, when they come from a page of their own
     */
    public function renderForReader(DeliverableInterface $deliverable, bool $printRequested, ?string $backUrl = null, ?string $view = null): Response
    {
        return $this->render($deliverable, $backUrl, print: $printRequested && self::allowsReaderPdf($deliverable), view: $view);
    }

    /**
     * The view requested by the address, if it is one: `page` or `slides`.
     * Anything else (empty, a typo, a forged value) counts as "nothing", and
     * the display chosen by the author applies. Read raw from the address: a
     * `?view[]=page` is an array, and it counts as "nothing" too instead of
     * breaking the page.
     */
    public static function requestedView(mixed $raw): ?string
    {
        return in_array($raw, DeliverableAppearance::DISPLAYS, true) ? $raw : null;
    }

    /** Whether the author allowed the reader to print their own PDF. */
    public static function allowsReaderPdf(DeliverableInterface $deliverable): bool
    {
        return DeliverableAppearance::normalize($deliverable->getAppearance())['readerPdf'];
    }

    /**
     * The page as the client will read it, rendered from what the editor
     * still holds without having saved it: the preview next to the grid, down
     * to the public theme, background, header and large headings included.
     * Each zone carries its id there, so that a click selects it.
     *
     * @param array<string, mixed> $payload what the editor sends
     */
    public function editorPreviewPage(array $payload): string
    {
        return $this->page(
            [
                'locale' => is_string($payload['locale'] ?? null) ? $payload['locale'] : $this->context->defaultLocale(),
                'title' => is_string($payload['title'] ?? null) ? $payload['title'] : '',
                'summary' => is_string($payload['summary'] ?? null) ? $payload['summary'] : null,
                'gridLayout' => is_array($payload['layout'] ?? null) ? $payload['layout'] : [],
                'gridContent' => is_array($payload['content'] ?? null) ? $payload['content'] : [],
                'appearance' => $payload['appearance'] ?? [],
                'readingHeader' => $payload['readingHeader'] ?? [],
                'updatedAt' => new DateTimeImmutable(),
            ],
            null,
            markPlaceholders: true,
            print: false,
            editorPreview: true,
        );
    }

    /**
     * @param array{locale: string, title: string, summary: ?string, gridLayout: array<string, mixed>, gridContent: array<string, mixed>, appearance: mixed, readingHeader: mixed, updatedAt: DateTimeInterface} $source
     */
    private function page(array $source, ?string $backUrl, bool $markPlaceholders, bool $print, bool $editorPreview = false, ?string $view = null): string
    {
        $locale = $source['locale'];
        $layout = self::withoutHiddenLayoutZones($source['gridLayout']);
        $content = self::contentOfShownZones($layout, $source['gridContent']);
        $grid = $editorPreview
            ? $this->gridViewBuilder->buildForPreview($layout, $content, $locale, null)
            : $this->gridViewBuilder->build($layout, $content, $locale, null);

        // The editor does not offer them, and the layout is already rid of
        // them; what is left to do is cut what the page does not have: a
        // comment thread.
        if (null !== $grid) {
            $grid['zones'] = $this->withoutHiddenZones($grid['zones']);
            $grid['hasComments'] = false;
        }

        $appearance = DeliverableAppearance::normalize($source['appearance']);

        // The view: the one the reader chose, otherwise the author's.
        // The editor preview always shows the author's.
        $display = ($editorPreview ? null : self::requestedView($view)) ?? $appearance['display'];

        // The document's sections, one per slide: the same split serves both
        // views, so that switching from one to the other lands at the same
        // place (`#diapo-N`).
        $sections = null !== $grid ? $this->gridSlides->split($grid['zones'], $content) : [];

        // Shown as a presentation: the same grid, cut at each section. Every
        // slide is a grid of its own for the template; the picture overlay is
        // left out of them, mounted once per grid it would open N times.
        $slides = null;
        if (null !== $grid && ('slides' === $display || $print)) {
            $slides = array_map(
                static fn (array $zones): array => [...$grid, 'zones' => $zones, 'lightbox' => []],
                $sections,
            );
        } elseif (null !== $grid && count($sections) > 1) {
            // As a page, the first zone of each section carries its number: a
            // `#diapo-N` address leads there, and switching back to the
            // presentation knows where you were.
            $grid['zones'] = $this->markSections($grid['zones'], $sections);
        }

        // Switch views, only when there are several sections: a presentation
        // of a single slide shows nothing more. Neither in the editor preview,
        // nor on the print version.
        $viewSwitch = !$print && !$editorPreview && count($sections) > 1
            ? ('slides' === $display ? 'page' : 'slides')
            : null;

        return $this->twig->render('@Studio/public/deliverable.html.twig', [
            'locale' => $locale,
            'context' => $this->context,
            'themeContext' => $this->themeContext,
            'deliverable' => [
                'title' => $source['title'],
                'summary' => $source['summary'],
            ],
            'appearance' => $appearance,
            'grid' => $grid,
            'slides' => $slides,
            'viewSwitch' => $viewSwitch,
            'print' => $print,
            'markPlaceholders' => $markPlaceholders,
            'editorPreview' => $editorPreview,
            'readingTimeMinutes' => null !== $grid ? $this->readingTimeCalculator->minutesFor($content) : 0,
            // The three surfaces the theme can repaint, surface by surface:
            // null lets the theme's own through.
            'surfaceOverrides' => [
                'background_color' => $appearance['backgroundColor'],
                'header_color' => $appearance['headerColor'],
                'footer_color' => $appearance['footerColor'],
            ],
            'reading' => [
                ...DeliverableReadingHeader::normalize($source['readingHeader']),
                'updatedAt' => $source['updatedAt']->format(DateTimeInterface::ATOM),
                'updatedOn' => new IntlDateFormatter($locale, IntlDateFormatter::LONG, IntlDateFormatter::NONE, $this->siteTimezone->get())->format($source['updatedAt']),
                // A single language: the selector is not drawn.
                'localeUrls' => [],
                'backUrl' => $backUrl,
            ],
        ]);
    }

    /**
     * Numbers the first zone of each section, and gives it the `diapo-N`
     * anchor when the author has not already set one.
     *
     * @param list<array<string, mixed>>       $zones
     * @param list<list<array<string, mixed>>> $sections
     *
     * @return list<array<string, mixed>>
     */
    private function markSections(array $zones, array $sections): array
    {
        $starts = [];
        foreach ($sections as $position => $section) {
            $first = $section[0]['id'] ?? null;
            if (is_string($first)) {
                $starts[$first] = $position + 1;
            }
        }

        foreach ($zones as $index => $zone) {
            $number = $starts[$zone['id'] ?? ''] ?? null;
            if (null === $number) {
                continue;
            }

            $zones[$index]['section'] = $number;
            if ('' === (string) ($zone['anchor'] ?? '')) {
                $zones[$index]['anchor'] = 'diapo-'.$number;
            }
        }

        return $zones;
    }

    /**
     * @param list<array<string, mixed>> $zones
     *
     * @return list<array<string, mixed>>
     */
    private function withoutHiddenZones(array $zones): array
    {
        $kept = [];

        foreach ($zones as $zone) {
            if (in_array($zone['type'] ?? null, self::HIDDEN_ZONE_TYPES, true)) {
                continue;
            }

            if (is_array($zone['children'] ?? null)) {
                $zone['children'] = $this->withoutHiddenZones($zone['children']);
            }

            $kept[] = $zone;
        }

        return $kept;
    }
}
