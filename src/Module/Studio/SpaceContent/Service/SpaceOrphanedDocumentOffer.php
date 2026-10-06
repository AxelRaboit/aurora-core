<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Service;

use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What nothing uses any more, offered rather than thrown away.
 *
 * Removing a file from a card, deleting a note, removing a file from a
 * space: three actions, a single follow-up. The usage registry says what has
 * become orphaned, and the response carries what is needed to send it to the
 * trash in one click, without ever doing it on somebody's behalf.
 *
 * **The right stays with the controller.** The offer is made only to whoever
 * can already delete a document, and `$mayTrash` says so: a button that
 * answered 403 would be worse than no button, and granting the privilege in
 * passing would be a privilege let in through the back door. This service
 * does not know who is looking, and that is deliberate - three controllers
 * each carried a copy of it, and one of the three had already forgotten the
 * guard once.
 */
final readonly class SpaceOrphanedDocumentOffer
{
    public function __construct(
        private SpaceOrphanedDocumentFinder $finder,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /**
     * @param list<DocumentInterface> $documents
     *
     * @return array{orphanedDocuments: list<array{id: int, title: string, trashPath: string}>}
     */
    public function payload(CustomerSpaceInterface $space, array $documents, bool $mayTrash): array
    {
        if (!$mayTrash) {
            return ['orphanedDocuments' => []];
        }

        $offered = [];

        foreach ($this->finder->among($space, $documents) as $document) {
            $offered[] = $document + [
                'trashPath' => $this->urlGenerator->generate('suite_ged_documents_delete', ['id' => $document['id']]),
            ];
        }

        return ['orphanedDocuments' => $offered];
    }
}
