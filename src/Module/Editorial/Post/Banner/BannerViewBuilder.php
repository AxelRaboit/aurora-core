<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Banner;

use Aurora\Core\Content\ContentValueNormalizer;
use Aurora\Core\Storage\Enum\MimeGroupEnum;
use Aurora\Core\Storage\Service\ImageRenditionGenerator;
use Aurora\Module\Configuration\Theme\Enum\ThemeFontEnum;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Service\DocumentCreditPresenter;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;

/**
 * Joins the two halves of a banner into what a template can render.
 *
 * The layout is stored on the post and the texts on one translation; a
 * template wants neither, it wants items that carry both. Merging here is
 * what keeps Twig from knowing the split exists, and is why the partial did
 * not change when the storage did.
 *
 * The stored shape also holds media *ids*; a template needs urls, alt text
 * and a focal position. Resolving that here rather than in Twig keeps the
 * template free of repository calls and lets every document - background,
 * logo, and one per item - be fetched in a single query.
 */
final readonly class BannerViewBuilder
{
    public function __construct(
        private DocumentRepository $documentRepository,
        private DocumentUrlGenerator $documentUrlGenerator,
        private DocumentCreditPresenter $creditPresenter,
        private BannerNormalizer $bannerNormalizer,
        private ContentValueNormalizer $values,
    ) {}

    /**
     * @param array<string, mixed> $layout the post's raw column value
     * @param array<string, mixed> $texts  the translation's raw column value
     *
     * @return array<string, mixed>|null null when the banner is off, so the
     *                                   template can fall back to the plain header
     */
    public function build(array $layout, array $texts): ?array
    {
        $banner = $this->resolve($layout, $texts);

        if (true !== $banner['enabled']) {
            return null;
        }

        // A banner with no items and no background would render as a coloured
        // void. Treat it as off rather than as a layout choice - an author who
        // cleared everything meant to remove it. This is also what catches the
        // banner whose only content was a background `mediaData` just refused
        // to resolve: the page falls back to its own header rather than
        // shipping an empty one.
        $hasContent = null !== $banner['background']['fillStyle']
            || null !== $banner['background']['media']
            || [] !== $banner['items']
            || $banner['stripes']['enabled']
            || [] !== $banner['slides'];

        return $hasContent ? $banner : null;
    }

    /**
     * A banner set in the body of a page, as a grid zone.
     *
     * The same banner, with one difference in what it says about the page:
     * it is never the page's title. The first title of each slide becomes an
     * `<h2>` - a section's heading - where the header above the page promotes
     * one to the `<h1>`; a page keeps a single `<h1>` however many banners
     * its body holds.
     *
     * @param array<string, mixed> $layout the zone's banner layout
     * @param array<string, mixed> $texts  the zone's banner words, one language
     *
     * @return array<string, mixed>|null null when there is nothing to draw
     */
    public function buildEmbedded(array $layout, array $texts): ?array
    {
        $banner = $this->build([...$layout, 'enabled' => true], $texts);

        if (null === $banner) {
            return null;
        }

        return [
            ...$banner,
            'embedded' => true,
            'headingTag' => 'h2',
            'slides' => array_map(
                fn (array $slide): array => [...$slide, 'headingIndex' => $this->headingIndex($slide['items'])],
                $banner['slides'],
            ),
        ];
    }

    /**
     * The editor needs the same resolved media, but unconditionally: a
     * disabled banner still has to show its picture in the picker, and an
     * empty one still has to render its form.
     *
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $texts
     *
     * @return array<string, mixed>
     */
    public function buildForEditor(array $layout, array $texts, int $focusSlide = 0): array
    {
        $banner = $this->resolve($layout, $texts);

        // The panel previews the slide being edited, not the carousel: a
        // preview is HTML dropped into the editor, no script turns it, so the
        // first slide would be all it ever showed. The chosen slide takes the
        // first one's place, and the rest are left out.
        $slide = $banner['slides'][$focusSlide - 1] ?? null;

        if (null === $slide) {
            return $banner;
        }

        return [
            ...$banner,
            'items' => $slide['items'],
            'background' => $slide['background'],
            'accentColor' => $slide['accentColor'],
            'accentStyle' => $slide['accentStyle'],
            'headingIndex' => $this->headingIndex($slide['items']),
            'slides' => [],
        ];
    }

    /**
     * One language's texts as the editor needs them: normalised, so a
     * translation saved before its own background existed arrives complete,
     * and with that background's pictures resolved so the pickers can preview
     * what they hold rather than just an id.
     *
     * @param array<string, mixed> $rawLayout the post's raw column value, which says which items exist
     * @param array<string, mixed> $rawTexts  the translation's raw column value
     *
     * @return array<string, mixed>
     */
    public function textsForEditor(array $rawLayout, array $rawTexts): array
    {
        $layout = $this->bannerNormalizer->normalizeLayout($rawLayout);
        $texts = $this->bannerNormalizer->normalizeTexts($rawTexts, $layout);
        $locals = [$texts['background']];
        foreach ($texts['slides'] as $slide) {
            $locals[] = $slide['background'];
        }

        $ids = [];
        foreach ($locals as $local) {
            $ids[] = $local['mediaId'];
            $ids[] = $local['mobileMediaId'];
            $ids[] = $local['tabletMediaId'];
        }

        $documents = $this->documentsById($ids);
        $withMedia = fn (array $local): array => [
            ...$local,
            'media' => $this->mediaData($documents[$local['mediaId']] ?? null, ''),
            'mobileMedia' => $this->mediaData($documents[$local['mobileMediaId']] ?? null, ''),
            'tabletMedia' => $this->mediaData($documents[$local['tabletMediaId']] ?? null, ''),
        ];

        return [
            ...$texts,
            'background' => $withMedia($texts['background']),
            'slides' => array_map(
                static fn (array $slide): array => [...$slide, 'background' => $withMedia($slide['background'])],
                $texts['slides'],
            ),
        ];
    }

    /**
     * @param array<string, mixed> $rawLayout
     * @param array<string, mixed> $rawTexts
     *
     * @return array<string, mixed>
     */
    private function resolve(array $rawLayout, array $rawTexts): array
    {
        $layout = $this->bannerNormalizer->normalizeLayout($rawLayout);
        $texts = $this->bannerNormalizer->normalizeTexts($rawTexts, $layout);

        $mainIds = $this->backgroundIds($layout['background'], $texts['background']);
        $slideIds = [];
        $ids = [$layout['logoMediaId'], ...$mainIds, ...array_column($layout['items'], 'mediaId')];

        foreach ($layout['slides'] as $slide) {
            $slideIds[$slide['id']] = $this->backgroundIds(
                $slide['background'],
                $texts['slides'][$slide['id']]['background'] ?? [],
            );
            array_push($ids, ...$slideIds[$slide['id']], ...array_column($slide['items'], 'mediaId'));
        }

        // Every picture of every slide in one query: a carousel of five is
        // still one round trip.
        $documents = $this->documentsById($ids);

        $main = $this->resolveSlide($layout['items'], $texts['items'], $layout['background'], $mainIds, $documents);

        $slides = array_map(
            function (array $slide) use ($texts, $slideIds, $documents): array {
                $resolved = $this->resolveSlide(
                    $slide['items'],
                    $texts['slides'][$slide['id']]['items'] ?? [],
                    $slide['background'],
                    $slideIds[$slide['id']],
                    $documents,
                );

                return [
                    'id' => $slide['id'],
                    'accentColor' => $slide['accentColor'],
                    'accentStyle' => $this->accentStyle($slide['accentColor']),
                    ...$resolved,
                    // A page has one <h1>, and it is the first slide's: the
                    // others take their turn in the same place, they are not
                    // the page's title.
                    'headingIndex' => null,
                ];
            },
            $layout['slides'],
        );

        return [
            ...$layout,
            // The banner replaces the page's own <h1>, so one of its titles has
            // to become it: a post with a banner otherwise ships no top-level
            // heading at all, which costs both search engines and anyone
            // navigating by headings. The first title wins; later ones stay
            // paragraphs.
            //
            // Computed per language, not per layout: a translation that has
            // not been written yet has no title to promote, and the template
            // then keeps the plain <h1> under the banner rather than leaving
            // the page without one.
            'headingIndex' => $this->headingIndex($main['items']),
            'items' => $main['items'],
            'background' => $main['background'],
            'accentStyle' => $this->accentStyle($layout['accentColor']),
            'logo' => $this->mediaData($documents[$layout['logoMediaId']] ?? null, ''),
            'stripes' => [
                ...$layout['stripes'],
                // The same reasoning as the fill: custom properties assembled
                // from integers the normaliser clamped, read by the stripes'
                // rule in the stylesheet.
                'style' => $this->stripesStyle($layout['stripes']),
            ],
            'slides' => $slides,
        ];
    }

    /**
     * The three pictures actually drawn behind one slide.
     *
     * The language's own picture wins over the shared one, field by field:
     * a translation may bring only a phone picture and keep the shared wide
     * one, or the reverse.
     *
     * @param array<string, mixed> $shared the slide's layout background
     * @param array<string, mixed> $local  the language's own background
     *
     * @return array{0: ?int, 1: ?int, 2: ?int} wide, phone and tablet
     */
    private function backgroundIds(array $shared, array $local): array
    {
        return [
            $local['mediaId'] ?? $shared['mediaId'],
            $local['mobileMediaId'] ?? $shared['mobileMediaId'],
            $local['tabletMediaId'] ?? $shared['tabletMediaId'],
        ];
    }

    /**
     * One slide's items and background, joined with their words and pictures.
     *
     * @param list<array<string, mixed>>       $items
     * @param array<string, mixed>             $itemTexts  texts keyed by item id
     * @param array<string, mixed>             $background the slide's layout background
     * @param array{0: ?int, 1: ?int, 2: ?int} $ids        from backgroundIds()
     * @param array<int, DocumentInterface>    $documents
     *
     * @return array{items: list<array<string, mixed>>, background: array<string, mixed>}
     */
    private function resolveSlide(array $items, array $itemTexts, array $background, array $ids, array $documents): array
    {
        [$backgroundId, $mobileId, $tabletId] = $ids;

        return [
            'items' => array_map(
                function (array $item) use ($itemTexts, $documents): array {
                    $text = $itemTexts[$item['id']] ?? ['title' => '', 'description' => '', 'alt' => '', 'label' => '', 'url' => null];

                    return [
                        ...$item,
                        ...$text,
                        'media' => $this->mediaData($documents[$item['mediaId']] ?? null, $text['alt']),
                        // Custom properties rather than classes: a span is a number
                        // between 1 and 48 chosen at runtime, and Tailwind only
                        // emits classes it can read in the source.
                        'spanStyle' => $this->values->spanStyle($item['span']),
                        // The CSS stack, built from the enum so the template never
                        // writes a font name it was sent.
                        'titleFontStack' => ThemeFontEnum::tryFrom((string) $item['titleFont'])?->stack(),
                        'descriptionFontStack' => ThemeFontEnum::tryFrom((string) $item['descriptionFont'])?->stack(),
                    ];
                },
                $items,
            ),
            'background' => [
                ...$background,
                'media' => $this->withFocal(
                    $this->mediaData($documents[$backgroundId] ?? null, ''),
                    $documents[$backgroundId] ?? null,
                    $background['focalX'] ?? null,
                    $background['focalY'] ?? null,
                ),
                // Null when no phone picture is set, and the template then
                // lets the phone crop the main one, as it always has.
                'mobileMedia' => $this->mediaData($documents[$mobileId] ?? null, ''),
                'tabletMedia' => $this->mediaData($documents[$tabletId] ?? null, ''),
                // Built here rather than in Twig so one place knows how a fill
                // becomes CSS. Safe to assemble as a string: the normaliser has
                // already reduced every part to a hex colour or an integer.
                'fillStyle' => $this->fillStyle($background),
            ],
        ];
    }

    /**
     * The banner's own crop of its wide picture, over the document's.
     *
     * Either axis may be set alone: the other keeps the document's point,
     * or the centre when the document has none. The document's own position
     * travels on as `documentFocalPosition`, for the editor to show where a
     * cleared setting falls back to.
     *
     * @param array<string, mixed>|null $media
     *
     * @return array<string, mixed>|null
     */
    private function withFocal(?array $media, ?DocumentInterface $document, ?float $focalX, ?float $focalY): ?array
    {
        if (null === $media) {
            return null;
        }

        $media['documentFocalPosition'] = $media['focalPosition'];

        if (null === $focalX && null === $focalY) {
            return $media;
        }

        $media['focalPosition'] = sprintf(
            '%s%% %s%%',
            round(($focalX ?? $document?->getFocalX() ?? 0.5) * 100, 2),
            round(($focalY ?? $document?->getFocalY() ?? 0.5) * 100, 2),
        );

        return $media;
    }

    /**
     * The theme's accent, replaced inside one slide.
     *
     * Everything written in the accent reads `--th-accent`, the `×` a title
     * colours in the editor included, so redefining it on the slide recolours
     * all of it without the template knowing which parts those are. A hex the
     * normaliser checked, so safe in a `style` attribute.
     */
    private function accentStyle(?string $color): ?string
    {
        return null !== $color ? sprintf('--th-accent: %s;', $color) : null;
    }

    /**
     * The custom properties the bands are drawn from.
     *
     * @param array{thickness: int, gap: int, angle: int, offset: int, opacity: int} $stripes
     */
    private function stripesStyle(array $stripes): string
    {
        return sprintf(
            '--stripe-thickness: %dpx; --stripe-gap: %dpx; --stripe-angle: %ddeg; --stripe-offset: %d%%; opacity: %s;',
            $stripes['thickness'],
            $stripes['gap'],
            -$stripes['angle'],
            $stripes['offset'],
            mb_rtrim(mb_rtrim(number_format($stripes['opacity'] / 100, 2, '.', ''), '0'), '.'),
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function headingIndex(array $items): ?int
    {
        foreach ($items as $index => $item) {
            // A title can carry colour spans: what counts is whether any
            // words are left once they are gone.
            if (BannerNormalizer::ITEM_TEXT === $item['type'] && '' !== mb_trim(strip_tags($item['title']))) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Every document named, in one query.
     *
     * @param list<?int> $ids
     *
     * @return array<int, DocumentInterface>
     */
    private function documentsById(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (?int $id): bool => null !== $id)));

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
     * The two widths a banner picture comes in, for a browser to pick from.
     *
     * A full-width banner on a 1440px Retina screen draws 2880 device pixels;
     * `large` stops at 1920, and stretched that far it goes visibly soft. The
     * `xlarge` rendition only exists when the source had the pixels, so without
     * it there is nothing to offer and the plain `src` is the whole answer.
     *
     * Width descriptors rather than `2x`: the banner's width is the viewport's,
     * not a fixed box, so the browser needs the real widths to choose.
     */
    private function srcset(DocumentInterface $media, string $largeUrl): ?string
    {
        $xlarge = $this->documentUrlGenerator->renditionUrl($media, 'xlarge');
        $width = $media->getWidth();
        $height = $media->getHeight();

        if (in_array(null, [$xlarge, $width, $height], true) || $width <= 0 || $height <= 0) {
            return null;
        }

        $fit = static fn (int $maxSide): int => (int) round($width * min(1, $maxSide / max($width, $height)));

        return sprintf(
            '%s %dw, %s %dw',
            $largeUrl,
            $fit(ImageRenditionGenerator::RENDITION_SIZES['large']),
            $xlarge,
            $fit(ImageRenditionGenerator::RENDITION_SIZES['xlarge']),
        );
    }

    /**
     * @param array<string, mixed> $background
     *
     * @return string|null the CSS declaration, or null when nothing is filled
     */
    private function fillStyle(array $background): ?string
    {
        return match ($background['type']) {
            BannerNormalizer::FILL_SOLID => null !== $background['color']
                ? sprintf('background-color: %s;', $background['color'])
                : null,
            // Both stops are required: a gradient with one colour is a solid
            // fill the author did not ask for, and guessing the other end
            // would be inventing a design decision.
            BannerNormalizer::FILL_GRADIENT => null !== $background['gradientFrom'] && null !== $background['gradientTo']
                ? sprintf(
                    'background-image: linear-gradient(%ddeg, %s, %s);',
                    $background['gradientAngle'],
                    $background['gradientFrom'],
                    $background['gradientTo'],
                )
                : null,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null null whenever there is no picture to
     *                                   draw, which the template reads as a
     *                                   background, a logo or an item that
     *                                   renders nothing
     */
    private function mediaData(?DocumentInterface $media, string $alt): ?array
    {
        if (!$media instanceof DocumentInterface) {
            return null;
        }

        // All three things a banner resolves end up in an `<img>`, so all three
        // have to hold an image. The picker only ever offers those, but a
        // fixture, an API write, and a document whose file is replaced after
        // the layout was saved all reach past it. That last one is why this is
        // asked at the render rather than refused in `BannerNormalizer`: the
        // normaliser has no database and runs on every render, and a layout
        // that was valid the day it was saved stops being valid the day the
        // file behind it changes. Only the render is there then.
        //
        // Answering null is louder here than in a grid zone - this feeds the
        // hero background. It is still the right answer, because the banner
        // already knows what to do without a picture: the fill is resolved
        // separately and stays, and a banner left with nothing at all is
        // switched off by `build`, which puts the page's own header and its
        // <h1> back. A stand-in picture or a grey fill would invent a design
        // decision the author never made - the same reason a gradient missing
        // a stop renders no gradient rather than a guessed one.
        if (!MimeGroupEnum::Image->matches($media->getMimeType())) {
            return null;
        }

        $url = $this->documentUrlGenerator->renditionUrl($media, 'large')
            ?? $this->documentUrlGenerator->publicUrl($media);

        // A document can carry no file at all - the demo library keeps three
        // that way on purpose, so the upload flow has something to be tested
        // against. Without this the banner emitted `<img src="">`, which is
        // worse than a missing picture: an empty src resolves to the page's own
        // address, so every view fetched the page a second time only to fail at
        // decoding it as an image.
        if (null === $url) {
            return null;
        }

        return [
            'url' => $url,
            'srcset' => $this->srcset($media, $url),
            // The file's own size, for a banner that takes its picture's
            // proportions instead of cropping it.
            'width' => $media->getWidth(),
            'height' => $media->getHeight(),
            // The item's own alt wins: the same picture can mean different
            // things in two banners, and the document's alt describes the file.
            'alt' => '' !== $alt ? $alt : (string) $media->getAlt(),
            'focalPosition' => $this->documentUrlGenerator->focalPositionCss($media),
            // Null for anything we host ourselves. Present, and displayed by
            // the template, for a stock photo whose licence requires it.
            'credit' => $this->creditPresenter->present($media),
        ];
    }
}
