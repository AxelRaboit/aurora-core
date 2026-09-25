<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Service;

use Aurora\Module\Editorial\Post\Entity\PostInterface;

use function array_keys;
use function in_array;
use function is_array;
use function is_int;

/**
 * Which pictures a post points at, wherever it keeps them.
 *
 * **One place that knows**, because there are now seven slots and they are not
 * in one shape: two are typed relations - the cover and each translation's
 * social image - and five are ids buried in JSON, the gallery's items, the
 * banner's logo, backdrop and pictures, each translation's own banner
 * backdrop, and the content grid.
 *
 * The grid is the one that bit. {@see PostRepository::findUsingDocument()}
 * read the gallery and the two relations and nothing else, so the library's
 * deletion screen answered "used by nobody" for any picture placed in a page
 * or in its banner. Measured on 2026-09-25 against the production data: of
 * the 163 documents a post was drawing, it saw 73, and reported the other 90
 * as free to delete.
 *
 * A grid zone holds pictures two ways, and both had to be walked: `mediaId`
 * for a single image, `mediaIds` for the lists a gallery or a before/after
 * zone arranges. Zones nest, since a stack holds zones of its own, so the
 * walk is recursive rather than one pass over the top level.
 *
 * Nothing here resolves a document. It answers with ids, and lets the caller
 * decide whether to load them, which is what makes it usable both from the
 * usage lookup and from a query that only needs to know whether the id is in
 * the list.
 */
final readonly class PostPictures
{
    /**
     * Every document id this post draws, once each, in no particular order.
     *
     * @return list<int>
     */
    public function idsUsedBy(PostInterface $post): array
    {
        $ids = [];

        $cover = $post->getThumbnail()?->getId();

        if (is_int($cover)) {
            $ids[$cover] = true;
        }

        foreach ($post->getTranslations() as $translation) {
            $social = $translation->getOgImage()?->getId();

            if (is_int($social)) {
                $ids[$social] = true;
            }

            $this->fromBackground($translation->getBanner()['background'] ?? null, $ids);
        }

        $this->fromGallery($post->getGalleryLayout(), $ids);
        $this->fromBanner($post->getBannerLayout(), $ids);
        $this->fromZones($post->getGridLayout()['zones'] ?? null, $ids);

        return array_keys($ids);
    }

    /**
     * Whether this post draws that document.
     *
     * Reads better than an `in_array` at the call site, and spares the caller
     * the reminder that the comparison has to be strict.
     */
    public function uses(PostInterface $post, int $documentId): bool
    {
        return in_array($documentId, $this->idsUsedBy($post), true);
    }

    /**
     * @param array<string, mixed> $gallery
     * @param array<int, true>     $ids
     */
    private function fromGallery(array $gallery, array &$ids): void
    {
        $items = $gallery['items'] ?? null;

        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if (is_array($item)) {
                $this->keep($item['mediaId'] ?? null, $ids);
            }
        }
    }

    /**
     * The banner keeps three kinds at once: the logo, the pictures behind the
     * whole thing, and one per item.
     *
     * @param array<string, mixed> $banner
     * @param array<int, true>     $ids
     */
    private function fromBanner(array $banner, array &$ids): void
    {
        $this->keep($banner['logoMediaId'] ?? null, $ids);
        $this->fromBackground($banner['background'] ?? null, $ids);

        $items = $banner['items'] ?? null;

        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if (is_array($item)) {
                $this->keep($item['mediaId'] ?? null, $ids);
            }
        }
    }

    /**
     * A banner background names two pictures, the wide one and the one a
     * phone gets instead. Shared by the layout and by each translation, which
     * may bring a background of its own.
     *
     * @param array<int, true> $ids
     */
    private function fromBackground(mixed $background, array &$ids): void
    {
        if (!is_array($background)) {
            return;
        }

        $this->keep($background['mediaId'] ?? null, $ids);
        $this->keep($background['mobileMediaId'] ?? null, $ids);
    }

    /**
     * A stack holds zones of its own, so this calls itself rather than
     * reading the top level and stopping there.
     *
     * @param array<int, true> $ids
     */
    private function fromZones(mixed $zones, array &$ids): void
    {
        if (!is_array($zones)) {
            return;
        }

        foreach ($zones as $zone) {
            if (!is_array($zone)) {
                continue;
            }

            $this->keep($zone['mediaId'] ?? null, $ids);

            $several = $zone['mediaIds'] ?? null;

            if (is_array($several)) {
                foreach ($several as $id) {
                    $this->keep($id, $ids);
                }
            }

            $this->fromZones($zone['children'] ?? null, $ids);
        }
    }

    /** @param array<int, true> $ids */
    private function keep(mixed $id, array &$ids): void
    {
        if (is_int($id) && $id > 0) {
            $ids[$id] = true;
        }
    }
}
