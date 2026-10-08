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
 * What the trash screen does to the spaces' contents.
 *
 * **Outside a space's address**, because the trash knows only one identifier
 * per row, as for deliverables: a space's board puts a content in the trash,
 * these routes take it out or destroy it.
 *
 * The right is the one that put it there, editing a space, and only on a
 * living space the person sees: a content of a space that is not theirs, or
 * of a space that is itself in the trash, answers 404 like an unknown
 * content.
 */
#[Route('/suite/studio/space-contents', name: 'suite_studio_space_contents')]
#[IsGranted('studio.spaces.edit')]
class SpaceContentTrashController extends AbstractController
{
    use JsonResponseTrait;

    public function __construct(
        protected readonly SpaceContentItemRepository $itemRepository,
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

    /** Empty the contents trash: only those of the spaces the person sees. */
    #[Route('/empty-trash', name: '_empty_trash', methods: [HttpMethodEnum::Post->value])]
    public function emptyTrash(): JsonResponse
    {
        $deleted = 0;
        foreach ($this->itemRepository->findAllTrashed() as $item) {
            if (!$this->visibility->canSee($item->getSpace())) {
                continue;
            }

            $this->itemManager->forceDelete($item);
            ++$deleted;
        }

        return $this->jsonSuccess(['deleted' => $deleted]);
    }

    /** A content in the trash, from a living space the person sees, or 404. */
    private function trashed(int $id): SpaceContentItemInterface
    {
        $item = $this->itemRepository->findTrashed($id);
        if (!$item instanceof SpaceContentItemInterface || !$this->visibility->canSee($item->getSpace())) {
            throw $this->createNotFoundException();
        }

        return $item;
    }
}
