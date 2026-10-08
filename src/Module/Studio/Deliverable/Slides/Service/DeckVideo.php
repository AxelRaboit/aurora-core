<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Slides\Service;

use Aurora\Core\Storage\Enum\MimeGroupEnum;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;

/**
 * A film id, as an address a free slide can play.
 *
 * The twin of `DeckPicture`, and for the same reason it exists: the
 * interesting part is the refusal. A document whose file is not a film
 * resolves to nothing, so a `<video>` is never pointed at a PDF that a fixture
 * or a replaced file slipped past the picker.
 *
 * The poster is the still the library already draws for every film it holds.
 * It is what the PDF prints in the film's place, and what the slide shows for
 * the second before the first frame arrives.
 */
final readonly class DeckVideo
{
    public function __construct(
        private DocumentRepository $documentRepository,
        private DocumentUrlGenerator $documentUrlGenerator,
    ) {}

    /**
     * Several films in one query, by id.
     *
     * @param list<int> $ids
     *
     * @return array<int, array{url: string, poster: string|null, mimeType: string}>
     */
    public function byIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $videos = [];

        foreach ($this->documentRepository->findBy(['id' => $ids]) as $document) {
            $video = $this->of($document);

            if (null !== $video) {
                $videos[(int) $document->getId()] = $video;
            }
        }

        return $videos;
    }

    /** @return array{url: string, poster: string|null, mimeType: string}|null */
    public function of(?DocumentInterface $document): ?array
    {
        if (!$document instanceof DocumentInterface) {
            return null;
        }

        $mime = (string) $document->getMimeType();

        if (!MimeGroupEnum::Video->matches($mime)) {
            return null;
        }

        $url = $this->documentUrlGenerator->publicUrl($document);

        if (null === $url) {
            return null;
        }

        return [
            'url' => $url,
            'poster' => $this->documentUrlGenerator->thumbnailPathUrl($document),
            'mimeType' => $mime,
        ];
    }
}
