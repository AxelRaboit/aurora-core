<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Banner;

use Aurora\Core\Content\ContentValueNormalizer;
use Aurora\Core\Storage\Enum\MimeGroupEnum;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
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
            || [] !== $banner['items'];

        return $hasContent ? $banner : null;
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
    public function buildForEditor(array $layout, array $texts): array
    {
        return $this->resolve($layout, $texts);
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
        $documents = $this->documents($layout);

        $items = array_map(
            function (array $item) use ($texts, $documents): array {
                $text = $texts['items'][$item['id']];

                return [
                    ...$item,
                    ...$text,
                    'media' => $this->mediaData($documents[$item['mediaId']] ?? null, $text['alt']),
                    // Custom properties rather than classes: a span is a number
                    // between 1 and 48 chosen at runtime, and Tailwind only
                    // emits classes it can read in the source.
                    'spanStyle' => $this->values->spanStyle($item['span']),
                ];
            },
            $layout['items'],
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
            'headingIndex' => $this->headingIndex($items),
            'items' => $items,
            'background' => [
                ...$layout['background'],
                'media' => $this->mediaData($documents[$layout['background']['mediaId']] ?? null, ''),
                // Built here rather than in Twig so one place knows how a fill
                // becomes CSS. Safe to assemble as a string: the normaliser has
                // already reduced every part to a hex colour or an integer.
                'fillStyle' => $this->fillStyle($layout['background']),
            ],
            'logo' => $this->mediaData($documents[$layout['logoMediaId']] ?? null, ''),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function headingIndex(array $items): ?int
    {
        foreach ($items as $index => $item) {
            if (BannerNormalizer::ITEM_TEXT === $item['type'] && '' !== $item['title']) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $layout
     *
     * @return array<int, DocumentInterface>
     */
    private function documents(array $layout): array
    {
        $ids = [$layout['logoMediaId'], $layout['background']['mediaId']];
        foreach ($layout['items'] as $item) {
            $ids[] = $item['mediaId'];
        }

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

        $url = $this->documentUrlGenerator->variantUrl($media, 'large')
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
            // The item's own alt wins: the same picture can mean different
            // things in two banners, and the document's alt describes the file.
            'alt' => '' !== $alt ? $alt : (string) $media->getAlt(),
            'focalPosition' => $this->documentUrlGenerator->focalPositionCss($media),
        ];
    }
}
