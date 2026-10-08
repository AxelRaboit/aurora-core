<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Storage\Access\UploadPolicyProvider;
use Aurora\Module\Ged\Document\Service\InlineImageUploader;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The slides of a Studio deliverable in the slides format: the presenter
 * view, printing, and the editor's writes.
 *
 * The gestures are those of {@see AbstractDeliverableSlidesController},
 * shared with a client space's presentation; only the access rule is this
 * controller's: the Studio deliverables' one, {@see DeliverableAccess}. A
 * deliverable one cannot read, one that lives in a space (it has addresses of
 * its own, see {@see SpaceDeliverableSlidesController}) or a page answers the
 * same 404 as an unknown id; one that can be read but not written answers 403
 * to the writes.
 */
#[Route('/suite/studio/deliverables/{id}', name: 'suite_studio_deliverables_slides', requirements: ['id' => '\d+'])]
#[IsGranted(DeliverableAccess::VIEW)]
final class DeliverableSlidesController extends AbstractDeliverableSlidesController
{
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
    public function presenter(int $id): Response
    {
        return $this->presenterPage($this->readable($id));
    }

    #[Route('/print', name: '_print', methods: [HttpMethodEnum::Get->value])]
    public function print(int $id, Request $request): Response
    {
        return $this->printPage($this->readable($id), $request);
    }

    #[Route('/appearance', name: '_appearance', methods: [HttpMethodEnum::Post->value])]
    public function appearance(int $id, Request $request): JsonResponse
    {
        return $this->writeAppearance($this->writable($id), $request);
    }

    #[Route('/slides/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    public function create(int $id, Request $request): JsonResponse
    {
        return $this->createSlide($this->writable($id), $request);
    }

    #[Route('/slides/{slideId}/update', name: '_update', requirements: ['slideId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function update(int $id, int $slideId, Request $request): JsonResponse
    {
        return $this->updateSlide($this->writable($id), $slideId, $request);
    }

    #[Route('/slides/{slideId}/duplicate', name: '_duplicate', requirements: ['slideId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function duplicate(int $id, int $slideId): JsonResponse
    {
        return $this->duplicateSlide($this->writable($id), $slideId);
    }

    #[Route('/slides/{slideId}/delete', name: '_delete', requirements: ['slideId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function delete(int $id, int $slideId): JsonResponse
    {
        return $this->deleteSlide($this->writable($id), $slideId);
    }

    #[Route('/slides/reorder', name: '_reorder', methods: [HttpMethodEnum::Post->value])]
    public function reorder(int $id, Request $request): JsonResponse
    {
        return $this->reorderSlides($this->writable($id), $request);
    }

    #[Route('/fonts/upload', name: '_font_upload', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.documents.create')]
    public function uploadFont(int $id, Request $request): JsonResponse
    {
        $this->writable($id);

        return $this->uploadFontFor($request);
    }

    /** A Studio presentation the reader may read, or 404. */
    private function readable(int $id): DeliverableInterface
    {
        $deliverable = $this->deliverableRepository->findStandalone($id);
        if (!$deliverable instanceof DeliverableInterface || !$deliverable->isSlides() || !$this->access->canRead($deliverable)) {
            throw new NotFoundHttpException();
        }

        return $deliverable;
    }

    /** A Studio presentation the reader may write: 404 without reading it, 403 without writing it. */
    private function writable(int $id): DeliverableInterface
    {
        $deliverable = $this->readable($id);
        if (!$this->access->canWrite($deliverable)) {
            throw $this->createAccessDeniedException();
        }

        return $deliverable;
    }
}
