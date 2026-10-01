<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Reading\View;

use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Reading\Entity\PostReadingLinkInterface;
use Aurora\Module\Editorial\Post\Reading\Repository\PostReadingLinkRepository;
use Aurora\Module\Editorial\Post\Service\PostPictures;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_map;
use function sprintf;

use const DATE_ATOM;

/**
 * What the reading links panel shows: the links, and what would surprise the
 * person they are sent to.
 *
 * Two surprises, both answered alongside the links so the panel can say so
 * where the author is standing. A publication that is not published opens
 * nothing, so its links lead to a 404 until it is. And a picture still in
 * draft in the library is not served to visitors, so the reader sees a gap
 * where the author sees a photo - the warning the deck's panel already gives.
 */
final readonly class PostReadingLinksViewBuilder
{
    public function __construct(
        private PostReadingLinkRepository $links,
        private PostPictures $pictures,
        private DocumentRepository $documents,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /** @return array<string, mixed> */
    public function payload(PostInterface $post): array
    {
        return [
            'readable' => $post->isPublished() && !$post->isTrashed(),
            'withheldPictures' => $this->withheldPictures($post),
            'links' => array_map($this->link(...), $this->links->findForPost($post)),
        ];
    }

    /** @return array<string, mixed> */
    private function link(PostReadingLinkInterface $link): array
    {
        return [
            'id' => $link->getId(),
            'label' => $link->getLabel(),
            'url' => $this->urlGenerator->generate(
                'post_reading_show',
                ['token' => $link->getToken()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            'expiresAt' => $link->getExpiresAt()?->format(DATE_ATOM),
            'revokedAt' => $link->getRevokedAt()?->format(DATE_ATOM),
            'lastUsedAt' => $link->getLastUsedAt()?->format(DATE_ATOM),
            'openCount' => $link->getOpenCount(),
            'locked' => $link->isLocked(),
            'createdAt' => $link->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return list<array{id: int, name: string}> */
    private function withheldPictures(PostInterface $post): array
    {
        $ids = $this->pictures->idsUsedBy($post);

        if ([] === $ids) {
            return [];
        }

        $withheld = [];

        foreach ($this->documents->findBy(['id' => $ids]) as $document) {
            if (DocumentStatusEnum::Published === $document->getStatus()) {
                continue;
            }

            $withheld[] = [
                'id' => (int) $document->getId(),
                'name' => $document->getOriginalName() ?? sprintf('#%d', $document->getId()),
            ];
        }

        return $withheld;
    }
}
