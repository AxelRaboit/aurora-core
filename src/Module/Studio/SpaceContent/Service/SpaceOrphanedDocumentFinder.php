<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Service;

use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Service\DocumentUsageService;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolderInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;

/**
 * Of the files a card was carrying, the ones now used by nobody.
 *
 * **Because taking a file off a card does not take it out of the library, and
 * three gestures do it.** Detaching, deleting the card, and deleting the whole
 * space all remove the join and leave the document: a draft in the space's
 * folder that nothing points at any more. It is not lost, but nothing surfaces
 * it either - the library's picker lists published documents only - so it is
 * found by browsing the folder or not at all, and on a space that runs a year
 * they pile up.
 *
 * **Asked after the removal rather than before.** The question is whether the
 * document is now unreferenced, and the join that was about to go is exactly
 * what would have answered "still used". Once it is gone the registry answers
 * plainly, and it answers for every module at once: a photograph also used as
 * a post's cover or on a deck comes back as used and is never offered.
 *
 * **Only what this space filed.** The folder is the signal, and it is truthful
 * because only uploads land there - a document picked from the library keeps
 * the folder it had. A studio that later moves one of these files elsewhere
 * has adopted it, and it stops being offered, which is the right answer rather
 * than a gap.
 *
 * It finds; it does not act. What happens next is offered to a person who
 * holds `ged.documents.delete`, and the trash catches the mistake if they were
 * wrong. A rule that binned them on its own would be deciding, in another
 * module, on the strength of one gesture.
 */
final readonly class SpaceOrphanedDocumentFinder
{
    public function __construct(private DocumentUsageService $usageService) {}

    /**
     * @param list<DocumentInterface> $documents the ones the removal detached
     *
     * @return list<array{id: int, title: string}>
     */
    public function among(CustomerSpaceInterface $space, array $documents): array
    {
        $folder = $space->getDocumentFolder();

        if (!$folder instanceof DocumentFolderInterface) {
            return [];
        }

        $orphaned = [];
        $seen = [];

        foreach ($documents as $document) {
            $id = (int) $document->getId();

            // The same document can hang on two cards of one card's deletion.
            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            if ($document->getFolder()?->getId() !== $folder->getId()) {
                continue;
            }

            if ($document->isTrashed()) {
                continue;
            }

            if (0 !== $this->usageService->findUsages($id)['total']) {
                continue;
            }

            $orphaned[] = ['id' => $id, 'title' => $document->getTitle()];
        }

        return $orphaned;
    }
}
