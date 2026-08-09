<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Grid;

use Aurora\Core\Content\ContentValueNormalizer;
use Aurora\Core\Content\VideoEmbedResolver;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\Post\Serializer\PostSerializerInterface;
use Aurora\Module\Editorial\Post\Service\BlocksRenderer;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;

/**
 * Joins the two halves of a content grid into what a template can render.
 *
 * The arrangement is stored on the post and what fills it on one translation;
 * a template wants neither, it wants zones carrying both. Merging here is what
 * keeps Twig from knowing the split exists — the same arrangement the banner
 * uses, and the reason its partial survived the storage changing underneath.
 *
 * Every id is resolved in one query per kind rather than one per zone: a page
 * of ten pictures should cost one document lookup, not ten.
 *
 * One class rather than a resolver per zone type. Four types is not enough to
 * earn an interface, and a per-type resolver would fetch its own rows, which
 * is exactly the N+1 the batching above avoids. When a project needs a zone
 * type of its own, that is the moment to invert this — not before.
 */
final readonly class GridViewBuilder
{
    public function __construct(
        private GridNormalizer $gridNormalizer,
        private ContentValueNormalizer $values,
        private DocumentRepository $documentRepository,
        private DocumentUrlGenerator $documentUrlGenerator,
        private PostRepository $postRepository,
        private PostSerializerInterface $postSerializer,
        private BlocksRenderer $blocksRenderer,
        private VideoEmbedResolver $videoEmbedResolver,
    ) {}

    /**
     * @param array<string, mixed> $layout  the post's raw column value
     * @param array<string, mixed> $content the translation's raw column value
     *
     * @return array<string, mixed>|null null when the grid is off or empty, so
     *                                   the template falls back to plain blocks
     */
    public function build(array $layout, array $content, string $locale): ?array
    {
        $grid = $this->resolve($layout, $content, $locale);

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
    public function buildForEditor(array $layout, array $content, string $locale): array
    {
        return $this->resolve($layout, $content, $locale);
    }

    /**
     * @param array<string, mixed> $rawLayout
     * @param array<string, mixed> $rawContent
     *
     * @return array<string, mixed>
     */
    private function resolve(array $rawLayout, array $rawContent, string $locale): array
    {
        $layout = $this->gridNormalizer->normalizeLayout($rawLayout);
        $content = $this->gridNormalizer->normalizeContent($rawContent, $layout);

        $documents = $this->documents($layout);
        $posts = $this->posts($layout);

        $zones = array_map(
            function (array $zone) use ($content, $documents, $posts, $locale): array {
                $held = $content['zones'][$zone['id']];

                return [
                    ...$zone,
                    'caption' => $held['caption'],
                    'spanStyle' => $this->values->spanStyle($zone['span']),
                    // One key per type, null for the others. The template reads
                    // the one its type names, and a zone whose target has been
                    // deleted since renders as nothing rather than as a hole
                    // with a broken image in it.
                    'html' => GridNormalizer::ZONE_TEXT === $zone['type']
                        ? $this->blocksRenderer->render($held['blocks'], $locale)
                        : null,
                    'media' => GridNormalizer::ZONE_MEDIA === $zone['type']
                        ? $this->mediaData($documents[$zone['mediaId']] ?? null, $held['alt'])
                        : null,
                    'post' => GridNormalizer::ZONE_POST === $zone['type']
                        ? $this->postCard($posts[$zone['postId']] ?? null, $locale)
                        : null,
                    'video' => GridNormalizer::ZONE_VIDEO === $zone['type']
                        ? $this->videoEmbedResolver->resolve($held['url'])
                        : null,
                    // Kept beside the embed so a zone whose address belongs to
                    // no known provider can still offer the link rather than
                    // silently showing nothing.
                    'url' => GridNormalizer::ZONE_VIDEO === $zone['type'] ? $held['url'] : null,
                ];
            },
            $layout['zones'],
        );

        return [
            ...$layout,
            'zones' => $zones,
        ];
    }

    /**
     * @param array<string, mixed> $layout
     *
     * @return array<int, DocumentInterface>
     */
    private function documents(array $layout): array
    {
        $ids = [];
        foreach ($layout['zones'] as $zone) {
            if (GridNormalizer::ZONE_MEDIA === $zone['type'] && null !== $zone['mediaId']) {
                $ids[] = $zone['mediaId'];
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
        foreach ($layout['zones'] as $zone) {
            if (GridNormalizer::ZONE_POST === $zone['type'] && null !== $zone['postId']) {
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
     * @return array<string, mixed>|null null when the post is gone, trashed,
     *                                   or has nothing written in this locale
     */
    private function postCard(?PostInterface $post, string $locale): ?array
    {
        if (!$post instanceof PostInterface || $post->isTrashed()) {
            return null;
        }

        $card = $this->postSerializer->serializeCard($post, $locale);

        // A card with no title and no address is a link to nowhere. That is
        // what an untranslated publication looks like, and it should leave a
        // gap rather than an empty box.
        return null !== $card['title'] && null !== $card['slug'] ? $card : null;
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
            // The zone's own alt wins: the same picture can mean different
            // things in two places, and the document's alt describes the file.
            'alt' => '' !== $alt ? $alt : (string) $media->getAlt(),
            'focalPosition' => $this->documentUrlGenerator->focalPositionCss($media),
        ];
    }
}
