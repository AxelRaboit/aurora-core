<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Storage\Access\UploadPolicyProvider;
use Aurora\Module\Ged\Document\Service\InlineImageUploader;
use Aurora\Module\Studio\CustomerSpace\Controller\SpaceOwnershipTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\EventSubscriber\SpaceVisibilitySubscriber;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Module\Studio\Deliverable\Security\DeliverableAccess;
use Aurora\Module\Studio\Deliverable\Slides\Serializer\SlidesSerializer;
use Aurora\Module\Studio\Deliverable\Slides\Service\DeckFonts;
use Aurora\Module\Studio\Deliverable\Slides\SlidesManager;
use Aurora\Module\Studio\Deliverable\View\DeliverableSlidesViewBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The slides of a presentation kept in a client space.
 *
 * The same gestures as in Studio ({@see AbstractDeliverableSlidesController}),
 * under the space's address and rights: one must see the space (its team, or
 * the roles that see everything, see {@see SpaceVisibilitySubscriber}),
 * `studio.spaces.view` to read, present and print, `studio.spaces.edit` to
 * write, as {@see DeliverableAccess} says of a space deliverable. The Studio
 * Deliverables module plays no part: a studio that switched it off keeps its
 * clients' presentations.
 *
 * A deliverable of another space, of Studio, or a page answers the same 404
 * as an unknown id; a presentation that can be read but not written answers
 * 403 to the writes.
 */
#[Route('/workspace/{id}/deliverables/{deliverableId}', name: 'workspace_space_deliverables_slides', requirements: ['id' => '\d+', 'deliverableId' => '\d+'])]
#[IsGranted('studio.spaces.view')]
final class SpaceDeliverableSlidesController extends AbstractDeliverableSlidesController
{
    use SpaceOwnershipTrait;

    public function __construct(
        private readonly DeliverableRepository $deliverableRepository,
        private readonly DeliverableAccess $access,
        SlidesManager $slides,
        SlidesSerializer $slidesSerializer,
        DeliverableSlidesViewBuilder $viewBuilder,
        EntityManagerInterface $entityManager,
        DeckFonts $fonts,
        InlineImageUploader $uploader,
        UploadPolicyProvider $uploadPolicies,
    ) {
        parent::__construct($slides, $slidesSerializer, $viewBuilder, $entityManager, $fonts, $uploader, $uploadPolicies);
    }

    #[Route('/presenter', name: '_presenter', methods: [HttpMethodEnum::Get->value])]
    public function presenter(CustomerSpace $space, int $deliverableId): Response
    {
        return $this->presenterPage($this->readable($space, $deliverableId));
    }

    #[Route('/print', name: '_print', methods: [HttpMethodEnum::Get->value])]
    public function print(CustomerSpace $space, int $deliverableId, Request $request): Response
    {
        return $this->printPage($this->readable($space, $deliverableId), $request);
    }

    #[Route('/appearance', name: '_appearance', methods: [HttpMethodEnum::Post->value])]
    public function appearance(CustomerSpace $space, int $deliverableId, Request $request): JsonResponse
    {
        return $this->writeAppearance($this->writable($space, $deliverableId), $request);
    }

    #[Route('/slides/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    public function create(CustomerSpace $space, int $deliverableId, Request $request): JsonResponse
    {
        return $this->createSlide($this->writable($space, $deliverableId), $request);
    }

    #[Route('/slides/{slideId}/update', name: '_update', requirements: ['slideId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function update(CustomerSpace $space, int $deliverableId, int $slideId, Request $request): JsonResponse
    {
        return $this->updateSlide($this->writable($space, $deliverableId), $slideId, $request);
    }

    #[Route('/slides/{slideId}/duplicate', name: '_duplicate', requirements: ['slideId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function duplicate(CustomerSpace $space, int $deliverableId, int $slideId): JsonResponse
    {
        return $this->duplicateSlide($this->writable($space, $deliverableId), $slideId);
    }

    #[Route('/slides/{slideId}/delete', name: '_delete', requirements: ['slideId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function delete(CustomerSpace $space, int $deliverableId, int $slideId): JsonResponse
    {
        return $this->deleteSlide($this->writable($space, $deliverableId), $slideId);
    }

    #[Route('/slides/reorder', name: '_reorder', methods: [HttpMethodEnum::Post->value])]
    public function reorder(CustomerSpace $space, int $deliverableId, Request $request): JsonResponse
    {
        return $this->reorderSlides($this->writable($space, $deliverableId), $request);
    }

    #[Route('/fonts/upload', name: '_font_upload', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.documents.create')]
    public function uploadFont(CustomerSpace $space, int $deliverableId, Request $request): JsonResponse
    {
        $this->writable($space, $deliverableId);

        return $this->uploadFontFor($request);
    }

    /**
     * A presentation of this space the reader may read, or 404: resolved by
     * the repository and typed as the interface, like the other space
     * deliverables, so a project that substitutes the entity keeps its routes.
     */
    private function readable(CustomerSpace $space, int $deliverableId): DeliverableInterface
    {
        $deliverable = $this->deliverableRepository->findLive($deliverableId);
        $this->assertOwned($space, $deliverable?->getSpace()?->getId());

        if (!$deliverable instanceof DeliverableInterface || !$deliverable->isSlides() || !$this->access->canRead($deliverable)) {
            throw $this->createNotFoundException();
        }

        return $deliverable;
    }

    /** A presentation of this space the reader may write: 404 without reading it, 403 without writing it. */
    private function writable(CustomerSpace $space, int $deliverableId): DeliverableInterface
    {
        $deliverable = $this->readable($space, $deliverableId);
        if (!$this->access->canWrite($deliverable)) {
            throw $this->createAccessDeniedException();
        }

        return $deliverable;
    }
}
