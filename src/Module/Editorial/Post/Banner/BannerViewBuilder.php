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
        $hasContent = null !== $banner['background']['color']
            || null !== $banner['background']['media']
            || [] !== array_filter(
                $banner['slots'],
                static fn (array $slot): bool => BannerNormalizer::SLOT_NONE !== $slot['type'],
            );

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

        return [
            ...$banner,
            'slots' => array_map(
                fn (array $slot): array => [
                    ...$slot,
                    'media' => $this->mediaData($documents[$slot['mediaId']] ?? null, $slot['alt']),
                ],
                $banner['slots'],
            ),
            'background' => [
                ...$banner['background'],
                'media' => $this->mediaData($documents[$banner['background']['mediaId']] ?? null, ''),
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
        foreach ($banner['slots'] as $slot) {
            $ids[] = $slot['mediaId'];
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
