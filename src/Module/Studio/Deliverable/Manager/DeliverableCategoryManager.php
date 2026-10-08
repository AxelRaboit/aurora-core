<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Manager;

use Aurora\Module\Studio\Deliverable\Dto\DeliverableCategoryInput;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableCategory;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableCategoryInterface;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;

use function array_flip;
use function count;
use function mb_trim;

/**
 * Create, rename, reorder and delete Studio deliverable categories.
 *
 * Deleting a category deletes no deliverable: the column is
 * `ON DELETE SET NULL`, its deliverables go back to "sans catégorie".
 */
readonly class DeliverableCategoryManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DeliverableCategoryRepository $deliverableCategoryRepository,
    ) {}

    /** A new category goes at the end: it can be moved up afterwards if wanted. */
    /** The category being created, in one place: a project that extends the entity overrides this. */
    protected function instantiate(): DeliverableCategoryInterface
    {
        return new DeliverableCategory();
    }

    public function create(DeliverableCategoryInput $input): DeliverableCategoryInterface
    {
        $category = $this->instantiate();
        $category->setPosition($this->deliverableCategoryRepository->nextPosition());
        $this->apply($category, $input);
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }

    public function update(DeliverableCategoryInterface $category, DeliverableCategoryInput $input): void
    {
        $this->apply($category, $input);
        $this->entityManager->flush();
    }

    public function delete(DeliverableCategoryInterface $category): void
    {
        $this->entityManager->remove($category);
        $this->entityManager->flush();
    }

    /**
     * The hand-picked order, from the list of ids received.
     *
     * An unknown id is ignored; a category missing from the list goes after
     * the others, in its previous order.
     *
     * @param list<int> $ids
     */
    public function reorder(array $ids): void
    {
        $rank = array_flip($ids);
        $next = count($ids);

        foreach ($this->deliverableCategoryRepository->findOrdered() as $category) {
            $category->setPosition($rank[$category->getId()] ?? $next++);
        }

        $this->entityManager->flush();
    }

    private function apply(DeliverableCategoryInterface $category, DeliverableCategoryInput $input): void
    {
        $category
            ->setName(mb_trim($input->name))
            ->setColor($input->color);
    }
}
