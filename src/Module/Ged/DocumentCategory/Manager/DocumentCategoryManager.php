<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\DocumentCategory\Manager;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Ged\DocumentCategory\Dto\DocumentCategoryInputInterface;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentCategory\Repository\DocumentCategoryRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\String\Slugger\AsciiSlugger;

#[AsAlias(DocumentCategoryManagerInterface::class)]
class DocumentCategoryManager implements DocumentCategoryManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly DocumentCategoryRepository $categoryRepository,
        protected readonly AuditLogger $auditLogger,
    ) {}

    public function create(DocumentCategoryInputInterface $input): DocumentCategoryInterface
    {
        $category = $this->createDocumentCategory();
        $this->applyInput($category, $input);
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        $this->auditCreated($category);

        return $category;
    }

    public function update(DocumentCategoryInterface $category, DocumentCategoryInputInterface $input): void
    {
        $this->applyInput($category, $input);
        $this->entityManager->flush();

        $this->auditUpdated($category);
    }

    /**
     * Moves a category to the trash.
     *
     * The documents keep pointing at it, which is what lets a restore put the
     * classification back: the `SET NULL` that used to scatter them only fires
     * on a real delete, and it was the part nobody could undo.
     *
     * The slug is left exactly as it was. It has to survive the stay untouched
     * for the trash to show a readable name, and it can: the unique index is
     * partial, so a category waiting here holds no name hostage.
     */
    public function delete(DocumentCategoryInterface $category): void
    {
        if ($category->isTrashed()) {
            return;
        }

        $category->setDeletedAt(new DateTimeImmutable());

        $this->entityManager->flush();

        $this->auditTrashed($category);
    }

    /**
     * Brings a category back, keeping its slug when it is still free.
     *
     * Only recomputed when a category created in the meantime has taken the
     * name: coming back as `factures-2` beats failing on a constraint, but
     * changing an address nothing was competing for would break links for no
     * reason.
     */
    public function restore(DocumentCategoryInterface $category): void
    {
        if (!$category->isTrashed()) {
            return;
        }

        $category->setDeletedAt(null);

        if ($this->slugExists($category->getSlug(), $category->getId())) {
            $category->setSlug($this->uniqueSlug($category->getName(), $category->getId()));
        }

        $this->entityManager->flush();

        $this->auditRestored($category);
    }

    /**
     * Deletes a category for good.
     *
     * The documents that carried it lose it here, through the database's own
     * `SET NULL`. That is the destructive half the trash exists to postpone.
     */
    public function forceDelete(DocumentCategoryInterface $category): void
    {
        $this->auditDeleted($category);

        $this->entityManager->remove($category);
        $this->entityManager->flush();
    }

    public function emptyTrash(): int
    {
        $categories = $this->categoryRepository->findAllTrashed();
        if ([] === $categories) {
            return 0;
        }

        foreach ($categories as $category) {
            $this->auditDeleted($category);
            $this->entityManager->remove($category);
        }

        $this->entityManager->flush();

        return count($categories);
    }

    /**
     * Destroys the categories that have been in the trash long enough.
     *
     * Same gesture as emptying the trash by hand, with a date instead of a
     * button: the documents that carried the category lose it through the
     * database's own `SET NULL`, and keep everything else.
     */
    public function purgeTrashedBefore(DateTimeImmutable $cutoff): int
    {
        $categories = $this->categoryRepository->findTrashedBefore($cutoff);
        if ([] === $categories) {
            return 0;
        }

        foreach ($categories as $category) {
            $this->auditDeleted($category);
            $this->entityManager->remove($category);
        }

        $this->entityManager->flush();

        return count($categories);
    }

    protected function createDocumentCategory(): DocumentCategoryInterface
    {
        return new DocumentCategory();
    }

    protected function applyInput(DocumentCategoryInterface $category, DocumentCategoryInputInterface $input): void
    {
        $category->setName($input->getName());
        $category->setDescription($input->getDescription());
        $category->setSlug($this->uniqueSlug($input->getName(), $category->getId()));
    }

    protected function auditTrashed(DocumentCategoryInterface $category): void
    {
        $this->auditLogger->log('ged', 'category.trashed', 'DocumentCategory', $category->getId(), $this->auditPayload($category));
    }

    protected function auditRestored(DocumentCategoryInterface $category): void
    {
        $this->auditLogger->log('ged', 'category.restored', 'DocumentCategory', $category->getId(), $this->auditPayload($category));
    }

    protected function auditCreated(DocumentCategoryInterface $category): void
    {
        $this->auditLogger->log('ged', 'category.created', 'DocumentCategory', $category->getId(), $this->auditPayload($category));
    }

    protected function auditUpdated(DocumentCategoryInterface $category): void
    {
        $this->auditLogger->log('ged', 'category.updated', 'DocumentCategory', $category->getId(), $this->auditPayload($category));
    }

    protected function auditDeleted(DocumentCategoryInterface $category): void
    {
        $this->auditLogger->log('ged', 'category.deleted', 'DocumentCategory', $category->getId(), $this->auditPayload($category));
    }

    protected function auditPayload(DocumentCategoryInterface $category): array
    {
        return ['name' => $category->getName()];
    }

    private function uniqueSlug(string $name, ?int $excludeId): string
    {
        $base = mb_strtolower(new AsciiSlugger()->slug($name)->toString());
        $slug = $base;
        $i = 2;
        while ($this->slugExists($slug, $excludeId)) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function slugExists(string $slug, ?int $excludeId): bool
    {
        $qb = $this->categoryRepository->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.slug = :slug')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('slug', $slug);

        if (null !== $excludeId) {
            $qb->andWhere('c.id != :id')->setParameter('id', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
