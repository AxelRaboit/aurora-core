<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Service;

use Aurora\Module\Ged\Document\Dto\DocumentInputInterface;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;

/**
 * Whether a document may be declared an alternate of the one it names.
 *
 * A family is one original and its alternates, and nothing deeper: the
 * screen shows an original with its alternates beside it, and a chain would
 * have no place to draw its third generation. So the original must be an
 * original itself, and a document that already has alternates cannot become
 * one - its alternates would end up two levels down.
 *
 * Asked by the controller before anything is written, because the answer
 * needs the database and the DTO's constraints cannot reach it.
 */
final readonly class DocumentFamilyRule
{
    public function __construct(private DocumentRepository $documentRepository) {}

    /**
     * @param DocumentInterface|null $document null on creation
     *
     * @return array<string, string> field => translation key, empty when allowed
     */
    public function errors(?DocumentInterface $document, DocumentInputInterface $input): array
    {
        $originalId = $input->getOriginalId();

        if (null === $originalId) {
            return [];
        }

        if (null !== $document?->getId() && $originalId === $document->getId()) {
            return ['originalId' => 'backend.ged.documents.errors.original_self'];
        }

        $original = $this->documentRepository->find($originalId);

        if (!$original instanceof DocumentInterface || $original->isTrashed()) {
            return ['originalId' => 'backend.ged.documents.errors.original_not_found'];
        }

        if ($original->getOriginal() instanceof DocumentInterface) {
            return ['originalId' => 'backend.ged.documents.errors.original_is_alternate'];
        }

        if ($document instanceof DocumentInterface && null !== $document->getId()
            && [] !== $this->documentRepository->countAlternatesFor([$document->getId()])) {
            return ['originalId' => 'backend.ged.documents.errors.original_has_alternates'];
        }

        return [];
    }
}
