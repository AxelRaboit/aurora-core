<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\DocumentCategory\Service;

use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentCategory\Repository\DocumentCategoryRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use RuntimeException;

/**
 * The category images uploaded from an editing form are filed under, created
 * on demand if it is not there.
 *
 * On demand and not only at install, because the install is the one step that
 * can be skipped. A project upgrading from a version that predates this would
 * have no such category until someone remembered to run `aurora:install`, and
 * every image uploaded in between would land uncategorised — the exact litter
 * this exists to prevent, arriving through the one door left open. The
 * bootstrap provider still seeds it so it shows up in the category list before
 * anyone uploads anything; this is what makes the upload independent of it.
 *
 * The slug is the identity. An administrator is free to rename the category —
 * it sits in their own list next to their own — and renaming it must not
 * silently create a second one.
 */
final readonly class InlineUploadCategoryProvider
{
    public const string SLUG = 'medias-editoriaux';

    public const string NAME_KEY = 'backend.ged.bootstrap.categories.inline_uploads';

    public const string DESCRIPTION_KEY = 'backend.ged.bootstrap.categories.inline_uploads_description';

    public function __construct(
        private ManagerRegistry $managerRegistry,
        private DocumentCategoryRepository $documentCategoryRepository,
        private DocumentCategoryFactory $documentCategoryFactory,
    ) {}

    public function resolve(): DocumentCategoryInterface
    {
        $existing = $this->find();

        if ($existing instanceof DocumentCategoryInterface) {
            return $existing;
        }

        return $this->create();
    }

    public function find(): ?DocumentCategoryInterface
    {
        return $this->documentCategoryRepository->findOneBy(['slug' => self::SLUG]);
    }

    private function create(): DocumentCategoryInterface
    {
        $entityManager = $this->managerRegistry->getManagerForClass(DocumentCategory::class);
        $category = $this->documentCategoryFactory->createInlineUploadCategory();

        try {
            $entityManager->persist($category);
            $entityManager->flush();

            return $category;
        } catch (UniqueConstraintViolationException) {
            // Two first-ever uploads racing each other. Rare enough that
            // preventing it would cost more than recovering from it, but the
            // loser must not fail: Doctrine closes the manager on a failed
            // flush, so it is reset before the row the winner just wrote is
            // read back.
            $this->managerRegistry->resetManager();

            $winner = $this->find();

            if (!$winner instanceof DocumentCategoryInterface) {
                throw new RuntimeException(sprintf('The "%s" category could not be created or found.', self::SLUG));
            }

            return $winner;
        }
    }
}
