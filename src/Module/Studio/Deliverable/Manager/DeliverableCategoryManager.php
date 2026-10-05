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
 * Créer, renommer, ranger et supprimer les catégories des livrables de Studio.
 *
 * Supprimer une catégorie ne supprime aucun livrable : la colonne est en
 * `ON DELETE SET NULL`, ses livrables redeviennent « sans catégorie ».
 */
readonly class DeliverableCategoryManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DeliverableCategoryRepository $categories,
    ) {}

    /** Une catégorie neuve se range à la fin : on la remonte ensuite si l'on veut. */
    /** La catégorie qu'on crée, à un seul endroit : un projet qui étend l'entité surcharge ceci. */
    protected function instantiate(): DeliverableCategoryInterface
    {
        return new DeliverableCategory();
    }

    public function create(DeliverableCategoryInput $input): DeliverableCategoryInterface
    {
        $category = $this->instantiate();
        $category->setPosition($this->categories->nextPosition());
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
     * L'ordre choisi à la main, d'après la liste des identifiants reçue.
     *
     * Un identifiant inconnu est ignoré ; une catégorie absente de la liste
     * passe après les autres, dans son ordre d'avant.
     *
     * @param list<int> $ids
     */
    public function reorder(array $ids): void
    {
        $rank = array_flip($ids);
        $next = count($ids);

        foreach ($this->categories->findOrdered() as $category) {
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
