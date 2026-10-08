<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Controller\Suite;

use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Http\PrivateAddressResponseTrait;
use Aurora\Core\Storage\Access\UploadPolicyProvider;
use Aurora\Core\Storage\Access\UploadRefusalEnum;
use Aurora\Module\Ged\Document\Service\InlineImageUploader;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Slides\Entity\SlideInterface;
use Aurora\Module\Studio\Deliverable\Slides\Enum\DeckThemeEnum;
use Aurora\Module\Studio\Deliverable\Slides\Enum\SlideLayoutEnum;
use Aurora\Module\Studio\Deliverable\Slides\Serializer\SlidesSerializer;
use Aurora\Module\Studio\Deliverable\Slides\Service\DeckFonts;
use Aurora\Module\Studio\Deliverable\Slides\SlidesManager;
use Aurora\Module\Studio\Deliverable\View\DeliverableSlidesViewBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function array_filter;
use function array_values;
use function is_array;
use function is_int;
use function is_string;

/**
 * The slide editor's gestures, written once for the two places a
 * presentation lives: Studio ({@see DeliverableSlidesController}) and a
 * client space ({@see SpaceDeliverableSlidesController}).
 *
 * **Only the address and the access rule differ.** Each controller resolves
 * the deliverable of its address (a Studio deliverable, or one of that
 * space), decides whether the reader may read and write it, then hands over
 * here. The routes stay on the concrete controllers: a route attribute on an
 * inherited method would be loaded twice, once per controller.
 *
 * Every slide is looked up among the slides of the deliverable received: an
 * id from elsewhere is never written through a deliverable one may open.
 */
abstract class AbstractDeliverableSlidesController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;
    use PrivateAddressResponseTrait;

    public function __construct(
        protected readonly SlidesManager $slidesManager,
        protected readonly SlidesSerializer $slidesSerializer,
        protected readonly DeliverableSlidesViewBuilder $viewBuilder,
        protected readonly EntityManagerInterface $entityManager,
        protected readonly DeckFonts $fonts,
        protected readonly InlineImageUploader $uploader,
        protected readonly UploadPolicyProvider $uploadPolicyProvider,
    ) {}

    /**
     * The presenter view: the speaker notes, on a second screen.
     *
     * Behind an account, like the whole back-office: that is the whole
     * difference with a reading link, which strips the notes.
     */
    protected function presenterPage(DeliverableInterface $deliverable): Response
    {
        return $this->privately($this->render('@Studio/suite/deliverables/slides_presenter.html.twig', [
            'deck' => $this->viewBuilder->deck($deliverable),
        ]));
    }

    /** The slides on paper; `?print=1` opens the print dialog. */
    protected function printPage(DeliverableInterface $deliverable, Request $request): Response
    {
        return $this->privately($this->render('@Studio/suite/deliverables/slides_print.html.twig', [
            'deck' => $this->viewBuilder->deck($deliverable),
            'autoPrint' => $request->query->getBoolean('print'),
        ]));
    }

    /** The slides' theme and what they adjust of it, from the editor's panel. */
    protected function writeAppearance(DeliverableInterface $deliverable, Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);

        $theme = DeckThemeEnum::tryFrom(is_string($payload['theme'] ?? null) ? $payload['theme'] : '');
        if (null === $theme) {
            return $this->jsonInvalidInput(['theme' => 'suite.studio.deliverables.slides.errors.theme_unknown']);
        }

        $this->slidesManager->writeAppearance($deliverable, $theme, is_array($payload['style'] ?? null) ? $payload['style'] : []);
        $deliverable->touch();
        $this->entityManager->flush();

        return $this->jsonSuccess($this->viewBuilder->appearancePayload($deliverable));
    }

    protected function createSlide(DeliverableInterface $deliverable, Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);

        $layout = SlideLayoutEnum::tryFrom(is_string($payload['layout'] ?? null) ? $payload['layout'] : '');
        if (null === $layout) {
            return $this->jsonInvalidInput(['layout' => 'suite.studio.deliverables.slides.errors.layout_unknown']);
        }

        $slide = $this->slidesManager->addSlide($deliverable, $layout);
        $deliverable->touch();
        $this->entityManager->flush();

        return $this->jsonSuccess(['slide' => $this->slidesSerializer->slide($slide)]);
    }

    /**
     * A slide's content, and its layout, which may change: the content is
     * then filtered by the slots of the *new* layout.
     */
    protected function updateSlide(DeliverableInterface $deliverable, int $slideId, Request $request): JsonResponse
    {
        $slide = $this->slidesManager->slideOf($deliverable, $slideId);
        if (!$slide instanceof SlideInterface) {
            return $this->jsonNotFound();
        }

        $payload = $this->decodeJson($request);

        $layout = SlideLayoutEnum::tryFrom(is_string($payload['layout'] ?? null) ? $payload['layout'] : '');
        if (null !== $layout) {
            $slide->setLayout($layout);
        }

        $this->slidesManager->writeContent($slide, is_array($payload['content'] ?? null) ? $payload['content'] : []);
        $slide->setSpeakerNotes(is_string($payload['speakerNotes'] ?? null) && '' !== $payload['speakerNotes'] ? $payload['speakerNotes'] : null);
        $deliverable->touch();
        $this->entityManager->flush();

        return $this->jsonSuccess(['slide' => $this->slidesSerializer->slide($slide)]);
    }

    /** A copy of the slide, placed right after it. */
    protected function duplicateSlide(DeliverableInterface $deliverable, int $slideId): JsonResponse
    {
        $slide = $this->slidesManager->slideOf($deliverable, $slideId);
        if (!$slide instanceof SlideInterface) {
            return $this->jsonNotFound();
        }

        $copy = $this->slidesManager->duplicateSlide($slide);
        $deliverable->touch();
        $this->entityManager->flush();

        return $this->jsonSuccess(['slide' => $this->slidesSerializer->slide($copy)]);
    }

    protected function deleteSlide(DeliverableInterface $deliverable, int $slideId): JsonResponse
    {
        $slide = $this->slidesManager->slideOf($deliverable, $slideId);
        if (!$slide instanceof SlideInterface) {
            return $this->jsonNotFound();
        }

        $this->slidesManager->removeSlide($deliverable, $slide);
        $deliverable->touch();
        $this->entityManager->flush();

        return $this->jsonSuccess();
    }

    /** The whole order, sent at once: a drag and drop lands anywhere. */
    protected function reorderSlides(DeliverableInterface $deliverable, Request $request): JsonResponse
    {
        $ids = $this->decodeJson($request)['orderedIds'] ?? null;

        $this->slidesManager->reorderSlides($deliverable, is_array($ids) ? array_values(array_filter($ids, is_int(...))) : []);
        $deliverable->touch();
        $this->entityManager->flush();

        return $this->jsonSuccess(['deck' => $this->viewBuilder->deck($deliverable)]);
    }

    /**
     * A font file, stored in the media library and offered at once.
     *
     * Two rights: writing this deliverable (checked by the concrete
     * controller), and adding a document to the media library, since the
     * file becomes one (the attribute on its route). A font must not be a
     * way around the second.
     */
    protected function uploadFontFor(Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->jsonFailure('suite.studio.deliverables.slides.free.font_errors.required');
        }

        if (!$this->fonts->isFontFile($file, $file->getClientOriginalName())) {
            return $this->jsonFailure('suite.studio.deliverables.slides.free.font_errors.not_a_font');
        }

        if ($this->uploadPolicyProvider->forStaffDocuments()->refusalFor($file) instanceof UploadRefusalEnum) {
            return $this->jsonFailure('suite.studio.deliverables.slides.free.font_errors.refused');
        }

        return $this->jsonSuccess(['font' => $this->fonts->describe($this->uploader->upload($file))]);
    }
}
