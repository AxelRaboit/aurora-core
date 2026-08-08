<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Banner;

use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;

/**
 * Turns a stored banner into what a template can render.
 *
 * The stored shape holds media *ids*; a template needs urls, alt text and a
 * focal position. Resolving that here rather than in Twig keeps the template
 * free of repository calls and lets the four possible documents — background,
 * logo, and one per slot — be fetched in a single query instead of four.
 */
final readonly class BannerViewBuilder
{
    public function __construct(
        private DocumentRepository $documentRepository,
        private DocumentUrlGenerator $documentUrlGenerator,
        private BannerNormalizer $bannerNormalizer,
    ) {}

    /**
     * @param array<string, mixed> $stored the raw column value
     *
     * @return array<string, mixed>|null null when the banner is off, so the
     *                                   template can fall back to the plain header
     */
    public function build(array $stored): ?array
    {
        $banner = $this->resolve($stored);

        if (true !== $banner['enabled']) {
            return null;
        }

        // A banner whose every slot is empty and which carries no background
        // would render as a coloured void. Treat it as off rather than as a
        // layout choice — an author who cleared everything meant to remove it.
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
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    public function buildForEditor(array $stored): array
    {
        return $this->resolve($stored);
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    private function resolve(array $stored): array
    {
        $banner = $this->bannerNormalizer->normalize($stored);
        $documents = $this->documents($banner);

        // The banner replaces the page's own <h1>, so one of its titles has to
        // become it: a post with a banner otherwise ships no top-level heading
        // at all, which costs both search engines and anyone navigating by
        // headings. The first title wins; later ones stay paragraphs.
        $headingIndex = null;
        foreach ($banner['items'] as $index => $item) {
            if (BannerNormalizer::ITEM_TEXT === $item['type'] && '' !== $item['title']) {
                $headingIndex = $index;
                break;
            }
        }

        return [
            ...$banner,
            // Null when no item carries a title: the template then keeps the
            // plain <h1> under the banner rather than leaving the page without
            // one, treating the banner as decoration.
            'headingIndex' => $headingIndex,
            'items' => array_map(
                fn (array $item): array => [
                    ...$item,
                    'media' => $this->mediaData($documents[$item['mediaId']] ?? null, $item['alt']),
                    // Custom properties rather than classes: a span is a number
                    // between 1 and 48 chosen at runtime, and Tailwind only
                    // emits classes it can read in the source.
                    'spanStyle' => $this->spanStyle($item['span']),
                ],
                $banner['items'],
            ),
            'background' => [
                ...$banner['background'],
                'media' => $this->mediaData($documents[$banner['background']['mediaId']] ?? null, ''),
                // Built here rather than in Twig so one place knows how a fill
                // becomes CSS. Safe to assemble as a string: the normaliser has
                // already reduced every part to a hex colour or an integer.
                'fillStyle' => $this->fillStyle($banner['background']),
            ],
            'logo' => $this->mediaData($documents[$banner['logoMediaId']] ?? null, ''),
        ];
    }

    /**
     * @param array<string, mixed> $banner
     *
     * @return array<int, DocumentInterface>
     */
    private function documents(array $banner): array
    {
        $ids = [$banner['logoMediaId'], $banner['background']['mediaId']];
        foreach ($banner['items'] as $item) {
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
            // The slot's own alt wins: the same picture can mean different
            // things in two banners, and the document's alt describes the file.
            'alt' => '' !== $alt ? $alt : (string) $media->getAlt(),
            'focalPosition' => $this->documentUrlGenerator->focalPositionCss($media),
        ];
    }
}
