<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Grid;

use Aurora\Core\Content\ContentValueNormalizer;
use Aurora\Core\Content\VideoEmbedResolver;
use Aurora\Core\Storage\Enum\MimeGroupEnum;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\Post\Service\BlocksRenderer;
use Aurora\Module\Editorial\Post\Service\ThumbnailPresenter;
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
        private BlocksRenderer $blocksRenderer,
        private VideoEmbedResolver $videoEmbedResolver,
        private ThumbnailPresenter $thumbnailPresenter,
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

        $resolve = function (array $zone) use (&$resolve, $content, $documents, $posts, $locale): array {
            $held = $content['zones'][$zone['id']];

            return [
                ...$zone,
                'caption' => $held['caption'],
                'spanStyle' => $this->values->spanStyle($zone['span']),
                'ratioStyle' => $this->ratioStyle($zone['ratio']),
                // A stack's own children, resolved the same way. The
                // recursion is bounded by the normaliser, which refuses a
                // stack inside a stack — so this descends once and stops.
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
                // Kept beside the embed so a zone whose address belongs to
                // no known provider can still offer the link rather than
                // silently showing nothing.
                'url' => GridNormalizer::ZONE_VIDEO === $zone['type'] ? $held['url'] : null,
            ];
        };

        $zones = array_map($resolve, $layout['zones']);

        // Applied after the zones are resolved rather than inside the walk,
        // because where a zone lands is a fact about the row it shares and not
        // about the zone: it cannot be known while resolving one at a time.
        //
        // Large screen only. Below that breakpoint every zone is full width, so
        // there is nothing to arrange, and the stylesheet reads an unset
        // property as `auto` — which is the plain flow this started as, and
        // what a theme that never emits this still gets.
        foreach (GridNormalizer::place($layout['zones']) as $index => $place) {
            $zones[$index]['startStyle'] = sprintf(
                '--row-lg: %d; --start-lg: %d;',
                $place['row'],
                $place['column'],
            );
        }

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
        // The whole tree, stacks included: a picture inside a stack must be
        // fetched by the same one query as the rest, not by a second one.
        foreach (GridNormalizer::flatten($layout['zones']) as $zone) {
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
        foreach (GridNormalizer::flatten($layout['zones']) as $zone) {
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
     * Built here rather than through PostSerializer, for two reasons. It would
     * be a circular dependency — the serialiser calls this builder to hand the
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
     * means something if its neighbours stop claiming a share of their own —
     * so they fall back to their own content height and the filling one takes
     * the rest.
     *
     * This is what an author asks for with a short paragraph beside a picture:
     * not "half each", which leaves a hole under three lines of text, but
     * "the text takes what it needs and the picture has the remainder". Shares
     * stop applying the moment one zone says it wants the remainder — one zone
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
     * A Tailwind class would have to be written out somewhere Tailwind reads —
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
            // rather than a declaration — see `_grid_zone.html.twig`.
            default => '',
        };
    }

    /**
     * @return array<string, mixed>|null null whenever there is no picture to
     *                                   draw, which the template reads as a
     *                                   zone that renders nothing
     */
    /**
     * @param ?string $url an address standing in for a document, used only when
     *                     no document is picked
     */
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
        // reach past it — a fixture, an API write, and a document whose file
        // is replaced after the zone was configured — and an `<img>` pointed
        // at an mp4 is a broken image with nothing said anywhere.
        //
        // Asked here rather than refused in `GridNormalizer` for two reasons.
        // The normaliser has no database and runs on every render, not only on
        // the way in — giving it a repository would put a query behind every
        // page view. And the third path above has no write to refuse: a layout
        // that was valid the day it was saved stops being valid the day the
        // file behind it changes. Only the render knows.
        if (!MimeGroupEnum::Image->matches($media->getMimeType())) {
            return null;
        }

        $url = $this->documentUrlGenerator->variantUrl($media, 'large')
            ?? $this->documentUrlGenerator->publicUrl($media);

        // A document can carry no file at all — the demo library keeps three
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
        ];
    }
}
