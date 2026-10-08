<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Http\PrivateAddressResponseTrait;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Aurora\Module\Studio\Deliverable\Dto\DeliverableCategoryInput;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableCategoryInterface;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableFormatEnum;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableScopeEnum;
use Aurora\Module\Studio\Deliverable\Manager\DeliverableCategoryManager;
use Aurora\Module\Studio\Deliverable\Manager\DeliverableManager;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableCategoryRepository;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Module\Studio\Deliverable\Security\DeliverableAccess;
use Aurora\Module\Studio\Deliverable\Serializer\DeliverableSerializer;
use Aurora\Module\Studio\Deliverable\Service\DeliverableEditorPreviews;
use Aurora\Module\Studio\Deliverable\Service\DeliverableLinkIssuer;
use Aurora\Module\Studio\Deliverable\Service\DeliverablePageRenderer;
use Aurora\Module\Studio\Deliverable\Slides\Import\SlidesFromBlocks;
use Aurora\Module\Studio\Deliverable\View\DeliverableLinksView;
use Aurora\Module\Studio\Deliverable\View\DeliverableSlidesViewBuilder;
use Aurora\Module\Studio\Deliverable\View\DeliverablesViewBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function array_key_exists;
use function array_values;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function mb_trim;

/**
 * Studio deliverables: the ones written without a client space, for yourself
 * or for the team.
 *
 * A space deliverable does not open here: its address is its space's, which
 * guards access to it. Here, an id that is not that of a readable Studio
 * deliverable answers the same 404 as an unknown id; a readable deliverable
 * you are not allowed to edit answers 403.
 *
 * The whole access rule is in {@see DeliverableAccess}.
 */
#[Route('/suite/studio/deliverables', name: 'suite_studio_deliverables')]
#[IsGranted(DeliverableAccess::VIEW)]
final class DeliverablesController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;
    use PrivateAddressResponseTrait;

    public function __construct(
        private readonly DeliverableRepository $deliverableRepository,
        private readonly DeliverableManager $manager,
        private readonly DeliverableAccess $access,
        private readonly DeliverablesViewBuilder $viewBuilder,
        private readonly DeliverableSerializer $serializer,
        private readonly DeliverablePageRenderer $renderer,
        private readonly DeliverableEditorPreviews $previews,
        private readonly DeliverableLinkIssuer $linkIssuer,
        private readonly DeliverableLinksView $linksView,
        private readonly TranslatorInterface $translator,
        private readonly CustomerSpaceRepository $spaceRepository,
        private readonly DeliverableCategoryRepository $deliverableCategoryRepository,
        private readonly DeliverableCategoryManager $categoryManager,
        private readonly PayloadValidator $payloadValidator,
        private readonly DeliverableSlidesViewBuilder $deliverableSlidesViewBuilder,
        private readonly SlidesFromBlocks $fromBlocks,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(Request $request): Response
    {
        return $this->render('@Studio/suite/deliverables/index.html.twig', [
            'view' => $this->viewBuilder->index(),
            // The tab opened by the address: coming back from a shared
            // deliverable lands in the shared ones.
            'scope' => DeliverableScopeEnum::Shared->value === $request->query->get('scope')
                ? DeliverableScopeEnum::Shared->value
                : DeliverableScopeEnum::Personal->value,
        ]);
    }

    /** Both shelves as the person sees them, to refresh the list. */
    #[Route('/lists', name: '_lists', methods: [HttpMethodEnum::Get->value])]
    public function lists(): JsonResponse
    {
        return $this->jsonSuccess($this->viewBuilder->lists());
    }

    /**
     * A title and a shelf, and you land in the editor.
     *
     * The format is chosen here and nowhere else: absent, it is a page;
     * `slides`, a slideshow, see {@see DeliverableFormatEnum}.
     *
     * Started from a template (`fromTemplateId`), the deliverable takes its
     * body (a page's grid, a slideshow's slides); the category too, unless
     * the request names one. A template you cannot read, that is no longer
     * one, or that is not in the requested format, gives an empty deliverable
     * rather than a refusal: the selector comes from the list, filtered by
     * format, and the only way to send a stale id is a template withdrawn
     * between opening the page and creating, which would lose the typed
     * title.
     */
    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->access->canCreate()) {
            return $this->jsonForbidden();
        }

        $payload = $this->decodeJson($request);
        $title = is_string($payload['title'] ?? null) ? mb_trim($payload['title']) : '';
        if ('' === $title) {
            return $this->jsonInvalidInput(['title' => 'suite.studio.deliverables.errors.title_required']);
        }

        if (mb_strlen($title) > DeliverableManager::TITLE_MAX) {
            return $this->jsonInvalidInput(['title' => 'suite.studio.deliverables.errors.title_too_long']);
        }

        $format = DeliverableFormatEnum::fromInput($payload['format'] ?? null);
        if (!$format instanceof DeliverableFormatEnum) {
            return $this->jsonInvalidInput(['format' => 'suite.studio.deliverables.errors.format_invalid']);
        }

        if (!$format->isCreatable()) {
            return $this->jsonInvalidInput(['format' => 'suite.studio.deliverables.errors.format_unavailable']);
        }

        $scope = DeliverableScopeEnum::fromInput($payload['scope'] ?? null);
        $template = $this->template($payload['fromTemplateId'] ?? null);
        // With no format sent, the template says it; with one, a template of
        // the other format does not count.
        if ($template instanceof DeliverableInterface && array_key_exists('format', $payload) && $template->getFormat() !== $format) {
            $template = null;
        }

        $deliverable = $template instanceof DeliverableInterface
            ? $this->manager->createFromTemplate(
                $template,
                $title,
                $this->access->user(),
                $scope,
                array_key_exists('categoryId', $payload) ? $this->manager->category($payload['categoryId']) : $template->getCategory(),
            )
            : $this->manager->create(
                null,
                $title,
                $this->access->user(),
                $scope,
                $this->manager->category($payload['categoryId'] ?? null),
                $format,
            );

        return $this->jsonSuccess([
            'editPath' => $this->generateUrl('suite_studio_deliverables_edit', ['id' => $deliverable->getId()]),
            ...$this->viewBuilder->lists(),
        ]);
    }

    /**
     * Text written elsewhere, pasted or typed in the dialog, that becomes a
     * presentation: a heading opens a slide, what follows fills it, see
     * {@see SlidesFromBlocks}.
     *
     * The conversion is done here rather than in the browser: every slide goes
     * through the manager and its allow list, like a slide typed in the
     * editor. Text that yields nothing is refused before the deliverable
     * exists: a failed import does not leave an empty presentation behind.
     */
    #[Route('/import', name: '_import', methods: [HttpMethodEnum::Post->value])]
    public function import(Request $request): JsonResponse
    {
        if (!$this->access->canCreate()) {
            return $this->jsonForbidden();
        }

        $payload = $this->decodeJson($request);
        $title = is_string($payload['title'] ?? null) ? mb_trim($payload['title']) : '';
        if ('' === $title) {
            return $this->jsonInvalidInput(['title' => 'suite.studio.deliverables.errors.title_required']);
        }

        if (mb_strlen($title) > DeliverableManager::TITLE_MAX) {
            return $this->jsonInvalidInput(['title' => 'suite.studio.deliverables.errors.title_too_long']);
        }

        $plan = $this->fromBlocks->plan(is_array($payload['blocks'] ?? null) ? array_values($payload['blocks']) : []);
        if ([] === $plan) {
            return $this->jsonInvalidInput(['blocks' => 'suite.studio.deliverables.errors.import_empty']);
        }

        $deliverable = $this->manager->create(
            null,
            $title,
            $this->access->user(),
            DeliverableScopeEnum::fromInput($payload['scope'] ?? null),
            $this->manager->category($payload['categoryId'] ?? null),
            DeliverableFormatEnum::Slides,
        );
        $this->fromBlocks->apply($deliverable, $plan);
        $this->entityManager->flush();

        return $this->jsonSuccess([
            'editPath' => $this->generateUrl('suite_studio_deliverables_edit', ['id' => $deliverable->getId()]),
            ...$this->viewBuilder->lists(),
        ]);
    }

    /**
     * The deliverable's editor: a page's grid, or a slideshow's slides in the
     * presentation editor.
     */
    #[Route('/{id}', name: '_edit', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function edit(int $id): Response
    {
        $deliverable = $this->readable($id);

        if ($deliverable->isSlides()) {
            return $this->render('@Studio/suite/deliverables/slides.html.twig', $this->deliverableSlidesViewBuilder->editorView($deliverable));
        }

        return $this->render('@Studio/suite/deliverables/edit.html.twig', $this->viewBuilder->editorView($deliverable));
    }

    #[Route('/{id}/update', name: '_update', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function update(int $id, Request $request): JsonResponse
    {
        $deliverable = $this->readable($id);
        if (!$this->access->canWrite($deliverable)) {
            return $this->jsonForbidden();
        }

        $payload = $this->decodeJson($request);

        // The client can only be named with the right to see clients: without
        // it, the editor sends back the one it received, and it does not count.
        if (!$this->access->canPickCustomer()) {
            unset($payload['customerId']);
        }

        // Before validation: saying the title is invalid when the real answer
        // is that a colleague saved in the meantime would be wrong.
        if ($this->manager->isStale($deliverable, $payload)) {
            return $this->jsonFailure('conflict', HttpStatusEnum::Conflict->value, ['conflict' => true]);
        }

        $errors = $this->manager->update($deliverable, $payload);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        // The shelf can also be changed from the editor settings, by the
        // author only: another member sends it without it counting.
        $scope = isset($payload['scope']) ? DeliverableScopeEnum::fromInput($payload['scope']) : null;
        if ($scope instanceof DeliverableScopeEnum && $scope !== $deliverable->getScope() && $this->access->canChangeScope($deliverable)) {
            $this->manager->setScope($deliverable, $scope, $this->access->user());
        }

        return $this->jsonSuccess(['deliverable' => $this->serializer->editor($deliverable)]);
    }

    /** Personal or shared, from the list. */
    #[Route('/{id}/scope', name: '_scope', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function scope(int $id, Request $request): JsonResponse
    {
        $deliverable = $this->readable($id);
        if (!$this->access->canChangeScope($deliverable)) {
            return $this->jsonForbidden();
        }

        // Strict, unlike creation: an empty body or a typo must not take a
        // shared deliverable away from the team. "perso" is not the default
        // value of an action that takes something away.
        $requested = $this->decodeJson($request)['scope'] ?? null;
        $scope = is_string($requested) ? DeliverableScopeEnum::tryFrom($requested) : null;
        if (!$scope instanceof DeliverableScopeEnum) {
            return $this->jsonInvalidInput(['scope' => 'suite.studio.deliverables.errors.scope_invalid']);
        }

        $this->manager->setScope($deliverable, $scope, $this->access->user());

        return $this->jsonSuccess($this->viewBuilder->lists());
    }

    #[Route('/{id}/duplicate', name: '_duplicate', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function duplicate(int $id): JsonResponse
    {
        $deliverable = $this->readable($id);
        if (!$this->access->canCreate()) {
            return $this->jsonForbidden();
        }

        $title = mb_substr(
            $this->translator->trans('suite.studio.deliverables.copy_title', ['%title%' => $deliverable->getTitle()]),
            0,
            DeliverableManager::TITLE_MAX,
        );

        $copy = $this->manager->duplicate($deliverable, $title, $this->access->user());

        return $this->jsonSuccess([
            'editPath' => $this->generateUrl('suite_studio_deliverables_edit', ['id' => $copy->getId()]),
            ...$this->viewBuilder->lists(),
        ]);
    }

    /**
     * A copy dropped into a client's space: the template you fill in for
     * them. You land in the copy's editor, in its space.
     * A presentation travels with its slides, notes, theme and style.
     *
     * An unknown, invisible or archived space answers 404, like a deliverable
     * you cannot read; a space you can see but not write to, 403.
     */
    #[Route('/{id}/copy-to-space', name: '_copy_to_space', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function copyToSpace(int $id, Request $request): JsonResponse
    {
        $source = $this->readable($id);
        $payload = $this->decodeJson($request);

        $spaceId = $payload['spaceId'] ?? null;
        $space = is_int($spaceId) || (is_string($spaceId) && is_numeric($spaceId)) ? $this->spaceRepository->find((int) $spaceId) : null;
        if (!$space instanceof CustomerSpaceInterface || $space->isArchived() || !$this->access->canReadSpace($space)) {
            return $this->jsonNotFound();
        }

        if (!$this->access->canCopyInto($space)) {
            return $this->jsonForbidden();
        }

        $title = is_string($payload['title'] ?? null) ? mb_trim($payload['title']) : '';
        if ('' === $title) {
            $title = $source->getTitle();
        }

        if (mb_strlen($title) > DeliverableManager::TITLE_MAX) {
            return $this->jsonInvalidInput(['title' => 'suite.studio.deliverables.errors.title_too_long']);
        }

        $copy = $this->manager->copyToSpace($source, $space, $title, $this->access->user());

        return $this->jsonSuccess(['editPath' => $this->serializer->path($copy, 'edit')]);
    }

    #[Route('/{id}/delete', name: '_delete', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function delete(int $id): JsonResponse
    {
        $deliverable = $this->readable($id);
        if (!$this->access->canDelete($deliverable)) {
            return $this->jsonForbidden();
        }

        // To the trash, not destroyed: it stays there for the common delay,
        // and its reading links resume if it comes back out.
        $this->manager->trash($deliverable);

        return $this->jsonSuccess($this->viewBuilder->lists());
    }

    /**
     * Take a deliverable out of the trash, from Studio or from a space: this
     * is what the trash screen calls for each of its deliverables. The right
     * is the right to edit it; a deliverable you cannot read answers 404.
     */
    #[Route('/{id}/restore', name: '_restore', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function restore(int $id): JsonResponse
    {
        $deliverable = $this->trashed($id);
        if (!$this->access->canWrite($deliverable)) {
            return $this->jsonForbidden();
        }

        $this->manager->restore($deliverable);

        return $this->jsonSuccess();
    }

    /** Destroy a trashed deliverable for good: the right to delete it, as before the trash existed. */
    #[Route('/{id}/force-delete', name: '_force_delete', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function forceDelete(int $id): JsonResponse
    {
        $deliverable = $this->trashed($id);
        if (!$this->access->canDelete($deliverable)) {
            return $this->jsonForbidden();
        }

        $this->manager->forceDelete($deliverable);

        return $this->jsonSuccess();
    }

    /**
     * Empty the trash: only what the person can read and is allowed to
     * delete, never a colleague's personal deliverable nor one from a space
     * that is not theirs.
     */
    #[Route('/empty-trash', name: '_empty_trash', methods: [HttpMethodEnum::Post->value])]
    public function emptyTrash(): JsonResponse
    {
        $deleted = 0;
        foreach ($this->deliverableRepository->findAllTrashed() as $deliverable) {
            if (!$this->access->canRead($deliverable)) {
                continue;
            }

            if (!$this->access->canDelete($deliverable)) {
                continue;
            }

            $this->manager->forceDelete($deliverable);
            ++$deleted;
        }

        return $this->jsonSuccess(['deleted' => $deleted]);
    }

    /** The page as the person receiving the link will read it. */
    #[Route('/{id}/preview', name: '_preview', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function preview(int $id, Request $request): Response
    {
        $deliverable = $this->readable($id);

        // A slideshow is previewed as the link's recipient will read it: its
        // slides, without the speaker notes.
        if ($deliverable->isSlides()) {
            return $this->privately($this->render('@Studio/public/deliverable_slides.html.twig', [
                'deck' => $this->deliverableSlidesViewBuilder->readerDeck($deliverable),
                'expiresAt' => null,
                // Opened in a new tab: closing it is the way out, the editor
                // only the fallback.
                'closeUrl' => $this->generateUrl('suite_studio_deliverables_edit', ['id' => $deliverable->getId()]),
            ]));
        }

        $print = $request->query->getBoolean('print');

        return $this->privately($this->renderer->render(
            $deliverable,
            $this->generateUrl('suite_studio_deliverables_edit', ['id' => $deliverable->getId()]),
            markPlaceholders: !$print,
            print: $print,
            view: DeliverablePageRenderer::requestedView($request->query->all()['view'] ?? null),
            // Opened in a new tab by the editor: its way out closes the tab
            // rather than opening a second editor inside it.
            closePreview: true,
        ));
    }

    #[Route('/grid-preview', name: '_grid_preview', methods: [HttpMethodEnum::Post->value])]
    public function gridPreview(Request $request): JsonResponse
    {
        return $this->json(['success' => true, 'html' => $this->previews->grid($this->decodeJson($request))]);
    }

    #[Route('/banner-preview', name: '_banner_preview', methods: [HttpMethodEnum::Post->value])]
    public function bannerPreview(Request $request): JsonResponse
    {
        return $this->json(['success' => true, 'html' => $this->previews->banner($this->decodeJson($request))]);
    }

    #[Route('/{id}/links', name: '_links', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function links(int $id): JsonResponse
    {
        // The list carries the addresses themselves, tokens included: reading
        // it means being able to pass them on. Same right as creating them.
        $deliverable = $this->readable($id);
        if (!$this->access->canShare($deliverable)) {
            return $this->jsonForbidden();
        }

        return $this->jsonSuccess($this->linksView->payload($deliverable));
    }

    #[Route('/{id}/links/create', name: '_links_create', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function createLink(int $id, Request $request): JsonResponse
    {
        $deliverable = $this->readable($id);
        if (!$this->access->canShare($deliverable)) {
            return $this->jsonForbidden();
        }

        $payload = $this->decodeJson($request);
        $errors = $this->linkIssuer->errors($payload);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->linkIssuer->issue($deliverable, $payload);

        return $this->jsonSuccess($this->linksView->payload($deliverable));
    }

    #[Route('/{id}/links/{linkId}/revoke', name: '_links_revoke', requirements: ['id' => '\d+', 'linkId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function revokeLink(int $id, int $linkId): JsonResponse
    {
        $deliverable = $this->readable($id);
        if (!$this->access->canShare($deliverable)) {
            return $this->jsonForbidden();
        }

        if (!$this->linkIssuer->revoke($deliverable, $linkId)) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess($this->linksView->payload($deliverable));
    }

    /** Hide a revoked or expired link from the list: its row stays, a live link cannot be hidden. */
    #[Route('/{id}/links/{linkId}/hide', name: '_links_hide', requirements: ['id' => '\d+', 'linkId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function hideLink(int $id, int $linkId): JsonResponse
    {
        $deliverable = $this->readable($id);
        if (!$this->access->canShare($deliverable)) {
            return $this->jsonForbidden();
        }

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
    #[Route('/{id}/links/{linkId}/delete', name: '_links_delete', requirements: ['id' => '\d+', 'linkId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function deleteLink(int $id, int $linkId): JsonResponse
    {
        $deliverable = $this->readable($id);
        if (!$this->access->canShare($deliverable)) {
            return $this->jsonForbidden();
        }

        $deleted = $this->linkIssuer->delete($deliverable, $linkId);
        if (null === $deleted) {
            return $this->jsonNotFound();
        }

        if (!$deleted) {
            return $this->jsonInvalidInput(['link' => 'suite.studio.sharing.errors.link_opened'], HttpStatusEnum::Conflict->value);
        }

        return $this->jsonSuccess($this->linksView->payload($deliverable));
    }

    #[Route('/categories/create', name: '_category_create', methods: [HttpMethodEnum::Post->value])]
    public function createCategory(Request $request): JsonResponse
    {
        if (!$this->access->canManageCategories()) {
            return $this->jsonForbidden();
        }

        $input = $this->categoryInput($request);
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $category = $this->categoryManager->create($input);

        return $this->jsonSuccess(['categoryId' => $category->getId(), ...$this->viewBuilder->categoriesPayload()]);
    }

    #[Route('/categories/{id}/update', name: '_category_update', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function updateCategory(int $id, Request $request): JsonResponse
    {
        if (!$this->access->canManageCategories()) {
            return $this->jsonForbidden();
        }

        $category = $this->deliverableCategoryRepository->find($id);
        if (!$category instanceof DeliverableCategoryInterface) {
            return $this->jsonNotFound();
        }

        $input = $this->categoryInput($request);
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->categoryManager->update($category, $input);

        return $this->jsonSuccess($this->viewBuilder->categoriesPayload());
    }

    /** Its deliverables stay, without a category. */
    #[Route('/categories/{id}/delete', name: '_category_delete', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function deleteCategory(int $id): JsonResponse
    {
        if (!$this->access->canManageCategories()) {
            return $this->jsonForbidden();
        }

        $category = $this->deliverableCategoryRepository->find($id);
        if (!$category instanceof DeliverableCategoryInterface) {
            return $this->jsonNotFound();
        }

        $this->categoryManager->delete($category);

        return $this->jsonSuccess($this->viewBuilder->categoriesPayload());
    }

    /** The category order, as arranged in the dialog. */
    #[Route('/categories/reorder', name: '_category_reorder', methods: [HttpMethodEnum::Post->value])]
    public function reorderCategories(Request $request): JsonResponse
    {
        if (!$this->access->canManageCategories()) {
            return $this->jsonForbidden();
        }

        $ids = $this->decodeJson($request)['ids'] ?? null;
        $this->categoryManager->reorder(is_array($ids) ? array_values(array_filter($ids, is_int(...))) : []);

        return $this->jsonSuccess($this->viewBuilder->categoriesPayload());
    }

    private function categoryInput(Request $request): DeliverableCategoryInput
    {
        $payload = $this->decodeJson($request);
        $color = is_string($payload['color'] ?? null) && '' !== $payload['color'] ? $payload['color'] : null;

        return new DeliverableCategoryInput(is_string($payload['name'] ?? null) ? mb_trim($payload['name']) : '', $color);
    }

    /** The template a new deliverable starts from: a live, readable Studio deliverable that is still a template. */
    private function template(mixed $id): ?DeliverableInterface
    {
        $id = is_int($id) || (is_string($id) && is_numeric($id)) ? (int) $id : null;
        $template = null === $id ? null : $this->deliverableRepository->findStandalone($id);

        return $template instanceof DeliverableInterface && $template->isTemplate() && $this->access->canRead($template) ? $template : null;
    }

    /** A trashed deliverable the person can read, or 404. */
    private function trashed(int $id): DeliverableInterface
    {
        $deliverable = $this->deliverableRepository->findTrashed($id);
        if (!$deliverable instanceof DeliverableInterface || !$this->access->canRead($deliverable)) {
            throw new NotFoundHttpException();
        }

        return $deliverable;
    }

    /** A Studio deliverable the person can read, or 404. */
    private function readable(int $id): DeliverableInterface
    {
        $deliverable = $this->deliverableRepository->findStandalone($id);
        if (!$deliverable instanceof DeliverableInterface || !$this->access->canRead($deliverable)) {
            throw new NotFoundHttpException();
        }

        return $deliverable;
    }
}
