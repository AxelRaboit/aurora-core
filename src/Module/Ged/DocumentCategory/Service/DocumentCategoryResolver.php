<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\DocumentCategory\Service;

use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentCategory\Repository\DocumentCategoryRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use RuntimeException;

use function sprintf;

/**
 * Finds a filing category by slug, creating it on the first use if it is not
 * there.
 *
 * Extracted from {@see InlineUploadCategoryProvider} when a second module
 * needed the same guarantee. The interesting part was never the lookup, it is
 * the two failure modes around it, and having them written twice is how they
 * end up fixed once:
 *
 * **On demand and not only at install**, because the install is the one step
 * that can be skipped. A project upgrading from a version that predates a
 * category would have none until somebody remembered to run `aurora:install`,
 * and everything filed in between would land uncategorised - the exact litter
 * these categories exist to prevent, arriving through the one door left open.
 *
 * **The race is recovered from rather than prevented.** Two first-ever uploads
 * can arrive together; Doctrine closes the manager on the failed flush, so the
 * loser resets it and reads back the row the winner wrote. Locking to prevent
 * it would cost more, on every upload, than recovering from something that
 * happens once in an application's life.
 *
 * The slug is the identity. An administrator is free to rename a category - it
 * sits in their own list next to their own - and renaming it must not silently
 * create a second one.
 */
final readonly class DocumentCategoryResolver
{
    public function __construct(
        private ManagerRegistry $managerRegistry,
        private DocumentCategoryRepository $documentCategoryRepository,
        private DocumentCategoryFactory $documentCategoryFactory,
    ) {}

    public function resolve(string $slug, string $nameKey, string $descriptionKey): DocumentCategoryInterface
    {
        $existing = $this->find($slug);

        if ($existing instanceof DocumentCategoryInterface) {
            return $existing;
        }

        return $this->create($slug, $nameKey, $descriptionKey);
    }

    public function find(string $slug): ?DocumentCategoryInterface
    {
        return $this->documentCategoryRepository->findOneBy(['slug' => $slug]);
    }

    private function create(string $slug, string $nameKey, string $descriptionKey): DocumentCategoryInterface
    {
        $entityManager = $this->managerRegistry->getManagerForClass(DocumentCategory::class);
        $category = $this->documentCategoryFactory->createCategory($slug, $nameKey, $descriptionKey);

        try {
            $entityManager->persist($category);
            $entityManager->flush();

            return $category;
        } catch (UniqueConstraintViolationException) {
            $this->managerRegistry->resetManager();

            $winner = $this->find($slug);

            if (!$winner instanceof DocumentCategoryInterface) {
                throw new RuntimeException(sprintf('The "%s" category could not be created or found.', $slug));
            }

            return $winner;
        }
    }
}
