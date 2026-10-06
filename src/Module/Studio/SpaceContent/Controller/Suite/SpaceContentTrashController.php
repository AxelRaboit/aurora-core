<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentItemManagerInterface;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ce que l'écran de la corbeille fait aux contenus des espaces.
 *
 * **Hors de l'adresse d'un espace**, parce que la corbeille ne connaît qu'un
 * identifiant par ligne, comme pour les livrables : le tableau d'un espace
 * met un contenu à la corbeille, ces routes l'en sortent ou le détruisent.
 *
 * Le droit est celui qui l'y a mis, modifier un espace, et seulement sur un
 * espace vivant que la personne voit : un contenu d'un espace qui n'est pas le
 * sien, ou d'un espace lui-même à la corbeille, répond 404 comme un contenu
 * inconnu.
 */
#[Route('/suite/studio/space-contents', name: 'suite_studio_space_contents')]
#[IsGranted('studio.spaces.edit')]
class SpaceContentTrashController extends AbstractController
{
    use JsonResponseTrait;

    public function __construct(
        protected readonly SpaceContentItemRepository $items,
        protected readonly SpaceContentItemManagerInterface $itemManager,
        protected readonly SpaceVisibility $visibility,
    ) {}

    #[Route('/{id}/restore', name: '_restore', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function restore(int $id): JsonResponse
    {
        $this->itemManager->restore($this->trashed($id));

        return $this->jsonSuccess();
    }

    #[Route('/{id}/force-delete', name: '_force_delete', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function forceDelete(int $id): JsonResponse
    {
        $this->itemManager->forceDelete($this->trashed($id));

        return $this->jsonSuccess();
    }

    /** Vider la corbeille des contenus : seulement ceux des espaces que la personne voit. */
    #[Route('/empty-trash', name: '_empty_trash', methods: [HttpMethodEnum::Post->value])]
    public function emptyTrash(): JsonResponse
    {
        $deleted = 0;
        foreach ($this->items->findAllTrashed() as $item) {
            if (!$this->visibility->canSee($item->getSpace())) {
                continue;
            }

            $this->itemManager->forceDelete($item);
            ++$deleted;
        }

        return $this->jsonSuccess(['deleted' => $deleted]);
    }

    /** Un contenu à la corbeille, d'un espace vivant que la personne voit, ou 404. */
    private function trashed(int $id): SpaceContentItemInterface
    {
        $item = $this->items->findTrashed($id);
        if (!$item instanceof SpaceContentItemInterface || !$this->visibility->canSee($item->getSpace())) {
            throw $this->createNotFoundException();
        }

        return $item;
    }
}
