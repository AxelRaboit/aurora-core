<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Http\PrivateAddressResponseTrait;
use Aurora\Module\Studio\CustomerSpace\Controller\SpaceOwnershipTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\EventSubscriber\SpaceVisibilitySubscriber;
use Aurora\Module\Studio\CustomerSpace\Security\ClientVisibility;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableFormatEnum;
use Aurora\Module\Studio\Deliverable\Manager\DeliverableManager;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Module\Studio\Deliverable\Security\DeliverableAccess;
use Aurora\Module\Studio\Deliverable\Serializer\DeliverableSerializer;
use Aurora\Module\Studio\Deliverable\Service\DeliverableEditorPreviews;
use Aurora\Module\Studio\Deliverable\Service\DeliverableLinkIssuer;
use Aurora\Module\Studio\Deliverable\Service\DeliverablePageRenderer;
use Aurora\Module\Studio\Deliverable\Service\DeliverableReadiness;
use Aurora\Module\Studio\Deliverable\Slides\Import\SlidesFromBlocks;
use Aurora\Module\Studio\Deliverable\View\DeliverableLinksView;
use Aurora\Module\Studio\Deliverable\View\DeliverableSlidesViewBuilder;
use Aurora\Module\Studio\Deliverable\View\SpaceDeliverablesViewBuilder;
use Aurora\Module\Studio\StudioContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_values;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function mb_trim;

/**
 * A space's deliverables, studio side.
 *
 * Everything goes through the space in the address: the subscriber that
 * guards spaces ({@see SpaceVisibilitySubscriber})
 * refuses a space you are not a member of, and every deliverable received
 * is checked against that space. An id from another space answers the same
 * 404 as an id that does not exist.
 */
#[Route('/workspace/{id}/deliverables', name: 'workspace_space_deliverables', requirements: ['id' => '\d+'])]
#[IsGranted('studio.spaces.view')]
final class SpaceDeliverablesController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;
    use PrivateAddressResponseTrait;
    use SpaceOwnershipTrait;

    public function __construct(
        private readonly DeliverableRepository $deliverables,
        private readonly DeliverableManager $manager,
        private readonly SpaceDeliverablesViewBuilder $viewBuilder,
        private readonly DeliverablePageRenderer $renderer,
        private readonly DeliverableEditorPreviews $previews,
        private readonly DeliverableLinkIssuer $linkIssuer,
        private readonly DeliverableLinksView $linksView,
        private readonly TranslatorInterface $translator,
        private readonly DeliverableAccess $access,
        private readonly DeliverableSerializer $serializer,
        private readonly DeliverableReadiness $readiness,
        private readonly ClientVisibility $clientVisibility,
        private readonly DeliverableSlidesViewBuilder $slidesView,
        private readonly SlidesFromBlocks $fromBlocks,
        private readonly EntityManagerInterface $entityManager,
        private readonly StudioContext $studioContext,
    ) {}

    /** The tab's rows as they are now, to refresh a stale list. */
    #[Route('/lists', name: '_lists', methods: [HttpMethodEnum::Get->value])]
    public function lists(CustomerSpace $space): JsonResponse
    {
        return $this->jsonSuccess(['deliverables' => $this->viewBuilder->rows($space)]);
    }

    /**
     * A title and a format, and one lands in the editor: a page or a
     * presentation, as in Studio.
     *
     * Started from a Studio template (`fromTemplateId`), the deliverable is a
     * copy of it dropped here: its grid, or its slides with their notes, theme
     * and style, see {@see DeliverableManager::copyToSpace()}. A template one
     * cannot read, that is no longer one, or of another format than the one
     * asked gives an empty deliverable rather than a refusal, for the reason
     * Studio's creation gives: the only way to send a stale id is a template
     * withdrawn between opening the modal and sending it, and a refusal would
     * lose the typed title.
     */
    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function create(CustomerSpace $space, Request $request): JsonResponse
    {
        // An archive takes no new deliverable, as it takes no copy.
        if (!$this->access->canAddTo($space)) {
            return $this->jsonForbidden();
        }

        $payload = $this->decodeJson($request);
        $title = $this->title($payload);
        if (is_string($title)) {
            return $this->jsonInvalidInput(['title' => $title]);
        }

        $format = DeliverableFormatEnum::fromInput($payload['format'] ?? null);
        if (!$format instanceof DeliverableFormatEnum) {
            return $this->jsonInvalidInput(['format' => 'suite.studio.deliverables.errors.format_invalid']);
        }

        if (!$format->isCreatable()) {
            return $this->jsonInvalidInput(['format' => 'suite.studio.deliverables.errors.format_unavailable']);
        }

        $template = $this->template($payload['fromTemplateId'] ?? null, $format);
        $name = mb_trim((string) $payload['title']);

        // Owned by whoever creates it, whichever way it was made.
        $deliverable = $template instanceof DeliverableInterface
            ? $this->manager->copyToSpace($template, $space, $name, $this->access->user())
            : $this->manager->create($space, $name, $this->access->user(), format: $format);

        return $this->jsonSuccess([
            'editPath' => $this->generateUrl('workspace_space_deliverables_edit', ['id' => $space->getId(), 'deliverableId' => $deliverable->getId()]),
            'deliverables' => $this->viewBuilder->rows($space),
        ]);
    }

    /**
     * A text written elsewhere that becomes a presentation of this space: a
     * heading opens a slide, what follows fills it, see
     * {@see SlidesFromBlocks}. The same conversion as in Studio, under the
     * space's rights; a text nothing can be drawn from is refused before the
     * deliverable exists.
     */
    #[Route('/import', name: '_import', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function import(CustomerSpace $space, Request $request): JsonResponse
    {
        if (!$this->access->canAddTo($space)) {
            return $this->jsonForbidden();
        }

        $payload = $this->decodeJson($request);
        $title = $this->title($payload);
        if (is_string($title)) {
            return $this->jsonInvalidInput(['title' => $title]);
        }

        $plan = $this->fromBlocks->plan(is_array($payload['blocks'] ?? null) ? array_values($payload['blocks']) : []);
        if ([] === $plan) {
            return $this->jsonInvalidInput(['blocks' => 'suite.studio.deliverables.errors.import_empty']);
        }

        $deliverable = $this->manager->create($space, mb_trim((string) $payload['title']), $this->access->user(), format: DeliverableFormatEnum::Slides);
        $this->fromBlocks->apply($deliverable, $plan);
        $this->entityManager->flush();

        return $this->jsonSuccess([
            'editPath' => $this->generateUrl('workspace_space_deliverables_edit', ['id' => $space->getId(), 'deliverableId' => $deliverable->getId()]),
            'deliverables' => $this->viewBuilder->rows($space),
        ]);
    }

    #[Route('/{deliverableId}', name: '_edit', requirements: ['deliverableId' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function edit(
        CustomerSpace $space,
        int $deliverableId,
    ): Response {
        $deliverable = $this->owned($space, $deliverableId);

        // A presentation is composed in the slide editor, inside the space
        // shell like a page.
        if ($deliverable->isSlides()) {
            return $this->render('@Studio/suite/space-deliverables/slides.html.twig', $this->slidesView->spaceEditorView($deliverable));
        }

        return $this->render('@Studio/suite/space-deliverables/edit.html.twig', $this->viewBuilder->editorView($deliverable));
    }

    #[Route('/{deliverableId}/update', name: '_update', requirements: ['deliverableId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function update(
        CustomerSpace $space,
        int $deliverableId,
        Request $request,
    ): JsonResponse {
        $deliverable = $this->owned($space, $deliverableId);

        $payload = $this->decodeJson($request);
        if ($this->manager->isStale($deliverable, $payload)) {
            return $this->jsonFailure('conflict', HttpStatusEnum::Conflict->value, ['conflict' => true]);
        }

        // The editor saves everything at once, the "Visible par le client"
        // box included: changing it takes the right to share the space, the
        // same as the list's button.
        if (!$this->clientVisibility->allowsChange($deliverable->isVisibleToClient(), true === ($payload['visibleToClient'] ?? false))) {
            return $this->jsonForbidden();
        }

        $errors = $this->manager->update($deliverable, $payload);

        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        return $this->jsonSuccess(['deliverable' => $this->viewBuilder->editorView($deliverable)['deliverable']]);
    }

    /** Shown or hidden from the client, from the list: the right to share the space. */
    #[Route('/{deliverableId}/visibility', name: '_visibility', requirements: ['deliverableId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    #[IsGranted(ClientVisibility::PRIVILEGE)]
    public function visibility(
        CustomerSpace $space,
        int $deliverableId,
        Request $request,
    ): JsonResponse {
        $deliverable = $this->owned($space, $deliverableId);

        $payload = $this->decodeJson($request);
        $visible = true === ($payload['visible'] ?? false);

        // Opening to the client is the moment a badly filled template reaches
        // them: the editor warns, the list must too. Refused until the author
        // has said they know (`confirm`), with what is left.
        if ($visible && true !== ($payload['confirm'] ?? false)) {
            $report = $this->readiness->report($deliverable);
            if ($report['placeholders'] > 0 || [] !== $report['withheldPictures']) {
                return $this->jsonFailure('confirmation_needed', HttpStatusEnum::Conflict->value, $report);
            }
        }

        $this->manager->setVisibleToClient($deliverable, $visible);

        return $this->jsonSuccess(['deliverables' => $this->viewBuilder->rows($space)]);
    }

    #[Route('/{deliverableId}/duplicate', name: '_duplicate', requirements: ['deliverableId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function duplicate(
        CustomerSpace $space,
        int $deliverableId,
    ): JsonResponse {
        $deliverable = $this->owned($space, $deliverableId);

        if (!$this->access->canAddTo($space)) {
            return $this->jsonForbidden();
        }

        $title = mb_substr(
            $this->translator->trans('suite.studio.deliverables.copy_title', ['%title%' => $deliverable->getTitle()]),
            0,
            DeliverableManager::TITLE_MAX,
        );
        $copy = $this->manager->duplicate($deliverable, $title, $this->access->user());

        return $this->jsonSuccess([
            'editPath' => $this->generateUrl('workspace_space_deliverables_edit', ['id' => $space->getId(), 'deliverableId' => $copy->getId()]),
            'deliverables' => $this->viewBuilder->rows($space),
        ]);
    }

    /**
     * A copy in Studio, to keep this deliverable as a template: it lands in
     * "Mes livrables", and its editor opens.
     */
    #[Route('/{deliverableId}/copy-to-studio', name: '_copy_to_studio', requirements: ['deliverableId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function copyToStudio(
        CustomerSpace $space,
        int $deliverableId,
    ): JsonResponse {
        $deliverable = $this->owned($space, $deliverableId);

        if (!$this->access->canCopyToStudio()) {
            return $this->jsonForbidden();
        }

        $copy = $this->manager->copyToStudio($deliverable, $deliverable->getTitle(), $this->access->user());

        return $this->jsonSuccess(['editPath' => $this->serializer->path($copy, 'edit')]);
    }

    #[Route('/{deliverableId}/delete', name: '_delete', requirements: ['deliverableId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function delete(
        CustomerSpace $space,
        int $deliverableId,
    ): JsonResponse {
        $deliverable = $this->owned($space, $deliverableId);

        $this->manager->trash($deliverable);

        return $this->jsonSuccess(['deliverables' => $this->viewBuilder->rows($space)]);
    }

    /**
     * The page as the client will read it, opened by the studio.
     *
     * Visible to the client or not: that is what allows reviewing before
     * opening.
     */
    #[Route('/{deliverableId}/preview', name: '_preview', requirements: ['deliverableId' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function preview(
        CustomerSpace $space,
        int $deliverableId,
        Request $request,
    ): Response {
        $deliverable = $this->owned($space, $deliverableId);

        // A presentation previews as the client will read it: its slides,
        // without the speaker notes.
        if ($deliverable->isSlides()) {
            return $this->privately($this->render('@Studio/public/deliverable_slides.html.twig', [
                'deck' => $this->slidesView->readerDeck($deliverable),
                'expiresAt' => null,
            ]));
        }

        $print = $request->query->getBoolean('print');

        return $this->privately($this->renderer->render(
            $deliverable,
            $this->generateUrl('workspace_space_deliverables_edit', ['id' => $space->getId(), 'deliverableId' => $deliverable->getId()]),
            markPlaceholders: !$print,
            print: $print,
            view: DeliverablePageRenderer::requestedView($request->query->all()['view'] ?? null),
        ));
    }

    /**
     * The grid preview while it is being composed: the same zone template as
     * the page, rendered from what the editor holds.
     */
    #[Route('/grid-preview', name: '_grid_preview', methods: [HttpMethodEnum::Post->value])]
    public function gridPreview(CustomerSpace $space, Request $request): JsonResponse
    {
        return $this->json(['success' => true, 'html' => $this->previews->grid($this->decodeJson($request))]);
    }

    /** The preview of a banner block placed in the grid. */
    #[Route('/banner-preview', name: '_banner_preview', methods: [HttpMethodEnum::Post->value])]
    public function bannerPreview(CustomerSpace $space, Request $request): JsonResponse
    {
        return $this->json(['success' => true, 'html' => $this->previews->banner($this->decodeJson($request))]);
    }

    /**
     * The reading links, addresses included: under the right to share the
     * space, like access to the space itself, and not under the right to edit
     * it. Reading them means being able to pass them on.
     */
    #[Route('/{deliverableId}/links', name: '_links', requirements: ['deliverableId' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    #[IsGranted(DeliverableAccess::SPACE_SHARE)]
    public function links(
        CustomerSpace $space,
        int $deliverableId,
    ): JsonResponse {
        $deliverable = $this->owned($space, $deliverableId);

        return $this->jsonSuccess($this->linksView->payload($deliverable));
    }

    /**
     * One more address: a label to find your way, an optional expiry and an
     * optional password.
     */
    #[Route('/{deliverableId}/links/create', name: '_links_create', requirements: ['deliverableId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted(DeliverableAccess::SPACE_SHARE)]
    public function createLink(
        CustomerSpace $space,
        int $deliverableId,
        Request $request,
    ): JsonResponse {
        $deliverable = $this->owned($space, $deliverableId);

        $payload = $this->decodeJson($request);
        $errors = $this->linkIssuer->errors($payload);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->linkIssuer->issue($deliverable, $payload);

        return $this->jsonSuccess($this->linksView->payload($deliverable));
    }

    /** Revoking dates the row; it is never deleted. */
    #[Route('/{deliverableId}/links/{linkId}/revoke', name: '_links_revoke', requirements: ['deliverableId' => '\d+', 'linkId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted(DeliverableAccess::SPACE_SHARE)]
    public function revokeLink(
        CustomerSpace $space,
        int $deliverableId,
        int $linkId,
    ): JsonResponse {
        $deliverable = $this->owned($space, $deliverableId);

        if (!$this->linkIssuer->revoke($deliverable, $linkId)) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess($this->linksView->payload($deliverable));
    }

    /** Hide a revoked or expired link from the list: its row stays, a live link cannot be hidden. */
    #[Route('/{deliverableId}/links/{linkId}/hide', name: '_links_hide', requirements: ['deliverableId' => '\d+', 'linkId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted(DeliverableAccess::SPACE_SHARE)]
    public function hideLink(
        CustomerSpace $space,
        int $deliverableId,
        int $linkId,
    ): JsonResponse {
        $deliverable = $this->owned($space, $deliverableId);

        $hidden = $this->linkIssuer->hide($deliverable, $linkId);
        if (null === $hidden) {
            return $this->jsonNotFound();
        }

        if (!$hidden) {
            return $this->jsonInvalidInput(['link' => 'suite.studio.sharing.errors.link_active'], HttpStatusEnum::Conflict->value);
        }

        return $this->jsonSuccess($this->linksView->payload($deliverable));
    }

    /** Delete an address nobody has ever opened; an address already opened can only be revoked. */
    #[Route('/{deliverableId}/links/{linkId}/delete', name: '_links_delete', requirements: ['deliverableId' => '\d+', 'linkId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted(DeliverableAccess::SPACE_SHARE)]
    public function deleteLink(
        CustomerSpace $space,
        int $deliverableId,
        int $linkId,
    ): JsonResponse {
        $deliverable = $this->owned($space, $deliverableId);

        $deleted = $this->linkIssuer->delete($deliverable, $linkId);
        if (null === $deleted) {
            return $this->jsonNotFound();
        }

        if (!$deleted) {
            return $this->jsonInvalidInput(['link' => 'suite.studio.sharing.errors.link_opened'], HttpStatusEnum::Conflict->value);
        }

        return $this->jsonSuccess($this->linksView->payload($deliverable));
    }

    /**
     * The error key refusing the title sent, or null when it is fine.
     *
     * @param array<string, mixed> $payload
     */
    private function title(array $payload): ?string
    {
        $title = is_string($payload['title'] ?? null) ? mb_trim($payload['title']) : '';

        if ('' === $title) {
            return 'suite.studio.deliverables.errors.title_required';
        }

        return mb_strlen($title) > DeliverableManager::TITLE_MAX ? 'suite.studio.deliverables.errors.title_too_long' : null;
    }

    /**
     * The Studio template a space deliverable starts from: live, readable,
     * still a template, of the format asked, with the Deliverables module on.
     * Otherwise nothing, and the deliverable starts from scratch.
     */
    private function template(mixed $id, DeliverableFormatEnum $format): ?DeliverableInterface
    {
        $id = is_int($id) || (is_string($id) && is_numeric($id)) ? (int) $id : null;
        if (null === $id || !$this->studioContext->areDeliverablesEnabled()) {
            return null;
        }

        $template = $this->deliverables->findStandalone($id);

        return $template instanceof DeliverableInterface
            && $template->isTemplate()
            && $template->getFormat() === $format
            && $this->access->canRead($template)
            ? $template
            : null;
    }

    /**
     * This space's deliverable, or 404: the id of another space's
     * deliverable, or of a Studio deliverable, answers like an id that does
     * not exist.
     *
     * Resolved by the repository and returned as the interface, not through a
     * `MapEntity` on the core class: a project that substitutes the entity
     * keeps its routes.
     */
    private function owned(CustomerSpace $space, int $deliverableId): DeliverableInterface
    {
        $deliverable = $this->deliverables->findLive($deliverableId);
        $this->assertOwned($space, $deliverable?->getSpace()?->getId());

        if (!$deliverable instanceof DeliverableInterface) {
            throw $this->createNotFoundException();
        }

        return $deliverable;
    }
}
