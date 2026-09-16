<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Service;

use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolder;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolderInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Doctrine\Persistence\ManagerRegistry;

use function mb_substr;

/**
 * The library folder a space files its uploads into, created on first use.
 *
 * **Because one shared category was not filing, it was piling.** Every space
 * upload landed in the `espaces-clients` category and nowhere else, so from
 * the second customer onwards the library held one undifferentiated heap:
 * nothing on a document said which space it came from, and the only thread
 * back was clicking it and reading the usage panel. That is a lookup, not an
 * arrangement.
 *
 * **On demand rather than with the space**, for the reason the category
 * resolver gives: a space that never receives a file would leave an empty
 * folder behind, and one empty folder per prospect is litter in a screen
 * people read.
 *
 * **At the top level, not nested under a shared parent.** A grouping folder
 * would need finding by name on every upload, and folder names carry no unique
 * index, so two first uploads racing would produce two parents rather than one
 * stray child. A folder per customer at the root is also what a studio expects
 * to see when it opens the library: its clients.
 *
 * **Only uploads are filed here.** Attaching a document that already lives in
 * the library leaves it exactly where it was. Moving somebody's file because
 * they referenced it from a card would be a side effect nobody asked for, and
 * the same document can be attached to two spaces.
 */
final readonly class SpaceDocumentFolderProvider
{
    /** `core_ged_document_folders.name` is 150. */
    private const int NAME_LIMIT = 150;

    public function __construct(private ManagerRegistry $managerRegistry) {}

    public function resolve(CustomerSpaceInterface $space): DocumentFolderInterface
    {
        $existing = $space->getDocumentFolder();

        // Trashed counts as gone. The folder belongs to the library once it
        // exists and somebody may bin it; the next upload then opens a fresh
        // one rather than filing into a folder that reads as deleted.
        if ($existing instanceof DocumentFolderInterface && !$existing->isTrashed()) {
            return $existing;
        }

        $entityManager = $this->managerRegistry->getManagerForClass(DocumentFolder::class);

        $folder = new DocumentFolder();
        $folder->setName(mb_substr($space->getName(), 0, self::NAME_LIMIT));

        $entityManager->persist($folder);
        $space->setDocumentFolder($folder);
        $entityManager->flush();

        return $folder;
    }
}
