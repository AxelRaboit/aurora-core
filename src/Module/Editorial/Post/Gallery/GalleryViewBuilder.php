<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Gallery;

use Aurora\Core\Storage\Enum\MimeGroupEnum;
use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;

use function is_array;

/**
 * A stored gallery, resolved into what a template can draw.
 *
 * The same division of labour as {@see GridViewBuilder}:
 * the normalizer settles the shape, this settles the values - the url of each
 * picture, its alt text, the CSS the layout implies - so no template works out a
 * ratio or reaches for a repository.
 *
 * One query for every picture, whatever the count. A gallery is the one place in
 * this module where sixty media ids is a normal payload, so a fetch per item
 * would be sixty round trips on a page that is already image-heavy.
 */
final readonly class GalleryViewBuilder
{
    public function __construct(
        private GalleryNormalizer $normalizer,
        private DocumentRepository $documentRepository,
        private DocumentUrlGenerator $documentUrlGenerator,
    ) {}

    /**
     * @param array<string, mixed> $layout  the post's raw column value
     * @param array<string, mixed> $content the translation's raw column value
     *
     * @return array<string, mixed>|null null when the gallery is off or has
     *                                   nothing to show, so the template can
     *                                   leave the section out entirely
     */
    public function build(array $layout, array $content): ?array
    {
        $gallery = $this->resolve($layout, $content);

        if (true !== $gallery['enabled'] || [] === $gallery['items']) {
            return null;
        }

        return $gallery;
    }

    /**
     * The editor needs the same resolved items, but unconditionally: a gallery
     * that is switched off still has to render its form.
     *
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    public function buildForEditor(array $layout, array $content): array
    {
        return $this->resolve($layout, $content);
    }

    /**
     * @param array<string, mixed> $rawLayout
     * @param array<string, mixed> $rawContent
     *
     * @return array<string, mixed>
     */
    private function resolve(array $rawLayout, array $rawContent): array
    {
        $layout = $this->normalizer->normalizeLayout($rawLayout);
        $content = $this->normalizer->normalizeContent($rawContent, $layout);
        $documents = $this->documents($layout);

        // Keyed by item id, under `items` since the normaliser became symmetric.
        // Read from the normalised copy and not the raw one, which is what makes
        // the shape a single question answered in a single place - including for
        // rows written as a bare map while it was asymmetric.
        $byId = is_array($content['items'] ?? null) ? $content['items'] : [];

        $items = [];
        foreach ($layout['items'] as $item) {
            $media = $documents[$item['mediaId']] ?? null;
            if (!$media instanceof DocumentInterface) {
                continue;
            }

            $words = $byId[$item['id']] ?? ['alt' => '', 'caption' => ''];
            $picture = $this->picture($media, (string) $words['alt']);

            // A media the library no longer serves as an image drops out here
            // rather than at render: the count the template shows and the number
            // of pictures it draws have to be the same number.
            if (null === $picture) {
                continue;
            }

            $items[] = [
                'id' => $item['id'],
                'mediaId' => $item['mediaId'],
                'caption' => (string) $words['caption'],
                ...$picture,
            ];
        }

        return [
            'enabled' => $layout['enabled'],
            'layout' => $layout['layout'],
            'columns' => $layout['columns'],
            'ratio' => $layout['ratio'],
            // A declaration rather than a class, for the reason the grid gives:
            // a ratio is chosen at runtime and Tailwind only emits classes it
            // can read in the source. Empty for `natural`, which states none.
            'ratioStyle' => $this->ratioStyle($layout['ratio']),
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $layout
     *
     * @return array<int, DocumentInterface>
     */
    private function documents(array $layout): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (array $item): int => $item['mediaId'],
            is_array($layout['items'] ?? null) ? $layout['items'] : [],
        )));

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
     * @return array<string, string>|null null when there is nothing to draw
     */
    private function picture(DocumentInterface $media, string $alt): ?array
    {
        // Checked at render rather than trusted from the save: a library entry
        // that was an image the day it was picked can stop being one the day the
        // file behind it is replaced.
        if (!MimeGroupEnum::Image->matches($media->getMimeType())) {
            return null;
        }

        $url = $this->documentUrlGenerator->variantUrl($media, 'large')
            ?? $this->documentUrlGenerator->publicUrl($media);

        // A document can carry no file at all - the demo library keeps three
        // that way on purpose. Without this the gallery emitted `<img src="">`,
        // which is a broken image rather than an absent one.
        if (null === $url) {
            return null;
        }

        return [
            'url' => $url,
            // The item's own alt wins over the document's: the same picture can
            // mean different things in two galleries, and the document's alt
            // describes the file.
            'alt' => '' !== $alt ? $alt : (string) $media->getAlt(),
            'focalPosition' => $this->documentUrlGenerator->focalPositionCss($media),
        ];
    }

    private function ratioStyle(string $ratio): string
    {
        return match ($ratio) {
            '16x9' => 'aspect-ratio: 16 / 9;',
            '4x3' => 'aspect-ratio: 4 / 3;',
            '1x1' => 'aspect-ratio: 1 / 1;',
            '3x4' => 'aspect-ratio: 3 / 4;',
            default => '',
        };
    }
}
