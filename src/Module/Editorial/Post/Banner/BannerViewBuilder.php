<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Banner;

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
 * template free of repository calls and lets every document — background,
 * logo, and one per item — be fetched in a single query.
 */
final readonly class BannerViewBuilder
{
    public function __construct(
        private DocumentRepository $documentRepository,
        private DocumentUrlGenerator $documentUrlGenerator,
        private BannerNormalizer $bannerNormalizer,
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
        // void. Treat it as off rather than as a layout choice — an author who
        // cleared everything meant to remove it.
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
                    'spanStyle' => $this->spanStyle($item['span']),
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
     * Widths as CSS custom properties, read by the `.aurora-grid` rule. An
     * absent breakpoint emits nothing, which is what makes it inherit the one
     * below through the variable's own fallback chain.
     *
     * @param array<string, int|null> $span
     */
    private function spanStyle(array $span): string
    {
        $declarations = [];

        foreach ($span as $breakpoint => $columns) {
            if (null !== $columns) {
                $declarations[] = sprintf('--span-%s: %d;', $breakpoint, $columns);
            }
        }

        return implode(' ', $declarations);
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

    /** @return array<string, mixed>|null */
    private function mediaData(?DocumentInterface $media, string $alt): ?array
    {
        if (!$media instanceof DocumentInterface) {
            return null;
        }

        return [
            'url' => $this->documentUrlGenerator->variantUrl($media, 'large')
                ?? $this->documentUrlGenerator->publicUrl($media),
            // The item's own alt wins: the same picture can mean different
            // things in two banners, and the document's alt describes the file.
            'alt' => '' !== $alt ? $alt : (string) $media->getAlt(),
            'focalPosition' => $this->documentUrlGenerator->focalPositionCss($media),
        ];
    }
}
