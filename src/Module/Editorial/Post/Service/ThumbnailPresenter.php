<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Service;

use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;

/**
 * What a card needs to draw a publication's thumbnail: a url, how it fills its
 * frame, and where the crop centres.
 *
 * The focal point has two possible sources and they answer different
 * questions. The document's is about the file — a face is in the same place
 * wherever that photo appears. The publication's is about *this* card, which
 * becomes a different question the moment a wide photo has to work in a narrow
 * frame. The publication's wins when it is set.
 *
 * One place because three templates draw this — the archive listing, a grid
 * zone linking to a publication, and any module that lists posts — and three
 * copies of a fallback is three chances for one of them to forget it.
 */
final readonly class ThumbnailPresenter
{
    public function __construct(
        private DocumentUrlGenerator $documentUrlGenerator,
    ) {}

    /**
     * @return array{url: ?string, fit: string, objectFitClass: string, focalPosition: string}
     */
    public function present(PostInterface $post, string $variant = 'medium'): array
    {
        $thumbnail = $post->getThumbnail();

        return [
            'url' => $this->documentUrlGenerator->variantUrl($thumbnail, $variant)
                ?? $this->documentUrlGenerator->publicUrl($thumbnail),
            'fit' => $post->getThumbnailFit()->value,
            // The class rather than the raw value: Tailwind only emits classes
            // it can read in the source, and `object-{{ fit }}` is one it
            // never sees.
            'objectFitClass' => $post->getThumbnailFit()->objectFitClass(),
            'focalPosition' => $this->focalPosition($post, $thumbnail),
        ];
    }

    private function focalPosition(PostInterface $post, ?DocumentInterface $thumbnail): string
    {
        $x = $post->getThumbnailFocalX();
        $y = $post->getThumbnailFocalY();

        if (null !== $x && null !== $y) {
            return sprintf('%s%% %s%%', round($x * 100, 2), round($y * 100, 2));
        }

        return $this->documentUrlGenerator->focalPositionCss($thumbnail);
    }
}
