<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Service;

use Aurora\Core\Storage\Enum\MimeGroupEnum;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;

/**
 * A picture id, as an address a slide can draw.
 *
 * One place rather than one per caller, because the interesting part is not
 * the lookup but the refusal: a document whose file is not an image resolves
 * to nothing at all. The picker only ever offers images, but a fixture, an API
 * write, or a document whose file is replaced after the deck was composed all
 * reach past it, and an `<img>` pointed at a PDF is a broken image with
 * nothing said anywhere.
 *
 * Addresses are never stored alongside the id. The address of a picture
 * changes when its file does, and a copy of it in a slide or in a deck's style
 * would be a second truth to maintain.
 */
final readonly class DeckPicture
{
    public function __construct(
        private DocumentRepository $documents,
        private DocumentUrlGenerator $documentUrls,
    ) {}

    /**
     * Several pictures in one query.
     *
     * A deck of thirty slides is thirty round trips otherwise, for a handful of
     * ids that are known before the loop starts.
     *
     * @param list<int> $ids
     *
     * @return array<int, array{url: string, alt: string}>
     */
    public function byIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $pictures = [];

        foreach ($this->documents->findBy(['id' => $ids]) as $document) {
            $picture = $this->of($document);

            if (null !== $picture) {
                $pictures[(int) $document->getId()] = $picture;
            }
        }

        return $pictures;
    }

    /** @return array{url: string, alt: string}|null */
    public function byId(?int $id): ?array
    {
        if (null === $id) {
            return null;
        }

        return $this->of($this->documents->find($id));
    }

    /** @return array{url: string, alt: string}|null */
    public function of(?DocumentInterface $document): ?array
    {
        if (!$document instanceof DocumentInterface) {
            return null;
        }

        if (!MimeGroupEnum::Image->matches($document->getMimeType())) {
            return null;
        }

        $url = $this->documentUrls->variantUrl($document, 'large')
            ?? $this->documentUrls->publicUrl($document);

        return null === $url ? null : ['url' => $url, 'alt' => $document->getAlt() ?? ''];
    }
}
