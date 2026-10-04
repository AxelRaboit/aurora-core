<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Http\PrivateAddressResponseTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableScopeEnum;
use Aurora\Module\Studio\Deliverable\Manager\DeliverableManager;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Module\Studio\Deliverable\Security\DeliverableAccess;
use Aurora\Module\Studio\Deliverable\Serializer\DeliverableSerializer;
use Aurora\Module\Studio\Deliverable\Service\DeliverableEditorPreviews;
use Aurora\Module\Studio\Deliverable\Service\DeliverableLinkIssuer;
use Aurora\Module\Studio\Deliverable\Service\DeliverablePageRenderer;
use Aurora\Module\Studio\Deliverable\View\DeliverableLinksView;
use Aurora\Module\Studio\Deliverable\View\DeliverablesViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_int;
use function is_numeric;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function mb_trim;

/**
 * Les livrables de Studio : ceux qu'on écrit sans espace client, pour soi ou
 * pour l'équipe.
 *
 * Un livrable d'espace ne s'ouvre pas ici : son adresse est celle de son
 * espace, qui en garde l'accès. Ici, un identifiant qui n'est pas celui d'un
 * livrable de Studio lisible répond le même 404 qu'un identifiant inconnu ;
 * un livrable lisible qu'on n'a pas le droit de modifier répond 403.
 *
 * Toute la règle d'accès est dans {@see DeliverableAccess}.
 */
#[Route('/backend/studio/deliverables', name: 'backend_studio_deliverables')]
#[IsGranted(DeliverableAccess::VIEW)]
final class DeliverablesController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;
    use PrivateAddressResponseTrait;

    public function __construct(
        private readonly DeliverableRepository $deliverables,
        private readonly DeliverableManager $manager,
        private readonly DeliverableAccess $access,
        private readonly DeliverablesViewBuilder $viewBuilder,
        private readonly DeliverableSerializer $serializer,
        private readonly DeliverablePageRenderer $renderer,
        private readonly DeliverableEditorPreviews $previews,
        private readonly DeliverableLinkIssuer $linkIssuer,
        private readonly DeliverableLinksView $linksView,
        private readonly TranslatorInterface $translator,
        private readonly CustomerSpaceRepository $spaces,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(Request $request): Response
    {
        return $this->render('@Studio/backend/deliverables/index.html.twig', [
            'view' => $this->viewBuilder->index(),
            // L'onglet ouvert par l'adresse : on revient d'un livrable partagé
            // dans les partagés.
            'scope' => DeliverableScopeEnum::Shared->value === $request->query->get('scope')
                ? DeliverableScopeEnum::Shared->value
                : DeliverableScopeEnum::Personal->value,
        ]);
    }

    /** Les deux rayons tels que la personne les voit, pour rafraîchir la liste. */
    #[Route('/lists', name: '_lists', methods: [HttpMethodEnum::Get->value])]
    public function lists(): JsonResponse
    {
        return $this->jsonSuccess($this->viewBuilder->lists());
    }

    /** Un titre et un rayon, et on arrive dans l'éditeur. */
    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->access->canCreate()) {
            return $this->jsonForbidden();
        }

        $payload = $this->decodeJson($request);
        $title = is_string($payload['title'] ?? null) ? mb_trim($payload['title']) : '';
        if ('' === $title) {
            return $this->jsonInvalidInput(['title' => 'backend.studio.deliverables.errors.title_required']);
        }

        if (mb_strlen($title) > DeliverableManager::TITLE_MAX) {
            return $this->jsonInvalidInput(['title' => 'backend.studio.deliverables.errors.title_too_long']);
        }

        $deliverable = $this->manager->create(null, $title, $this->access->user(), DeliverableScopeEnum::fromInput($payload['scope'] ?? null));

        return $this->jsonSuccess([
            'editPath' => $this->generateUrl('backend_studio_deliverables_edit', ['id' => $deliverable->getId()]),
            ...$this->viewBuilder->lists(),
        ]);
    }

    #[Route('/{id}', name: '_edit', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function edit(int $id): Response
    {
        return $this->render('@Studio/backend/deliverables/edit.html.twig', $this->viewBuilder->editorView($this->readable($id)));
    }

    #[Route('/{id}/update', name: '_update', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function update(int $id, Request $request): JsonResponse
    {
        $deliverable = $this->readable($id);
        if (!$this->access->canWrite($deliverable)) {
            return $this->jsonForbidden();
        }

        $payload = $this->decodeJson($request);
        $errors = $this->manager->update($deliverable, $payload);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        // Le rayon se change aussi depuis les réglages de l'éditeur, par
        // l'auteur seul : un autre membre l'envoie sans qu'il compte.
        $scope = isset($payload['scope']) ? DeliverableScopeEnum::fromInput($payload['scope']) : null;
        if ($scope instanceof DeliverableScopeEnum && $scope !== $deliverable->getScope() && $this->access->canChangeScope($deliverable)) {
            $this->manager->setScope($deliverable, $scope, $this->access->user());
        }

        return $this->jsonSuccess(['deliverable' => $this->serializer->editor($deliverable)]);
    }

    /** Perso ou partagé, depuis la liste. */
    #[Route('/{id}/scope', name: '_scope', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function scope(int $id, Request $request): JsonResponse
    {
        $deliverable = $this->readable($id);
        if (!$this->access->canChangeScope($deliverable)) {
            return $this->jsonForbidden();
        }

        $this->manager->setScope($deliverable, DeliverableScopeEnum::fromInput($this->decodeJson($request)['scope'] ?? null), $this->access->user());

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
            $this->translator->trans('backend.studio.deliverables.copy_title', ['%title%' => $deliverable->getTitle()]),
            0,
            DeliverableManager::TITLE_MAX,
        );

        $copy = $this->manager->duplicate($deliverable, $title, $this->access->user());

        return $this->jsonSuccess([
            'editPath' => $this->generateUrl('backend_studio_deliverables_edit', ['id' => $copy->getId()]),
            ...$this->viewBuilder->lists(),
        ]);
    }

    /**
     * Une copie déposée dans l'espace d'un client : le modèle qu'on remplit
     * pour lui. On arrive dans l'éditeur de la copie, dans son espace.
     *
     * Un espace inconnu, invisible ou archivé répond 404, comme un livrable
     * qu'on ne lit pas ; un espace qu'on voit sans pouvoir y écrire, 403.
     */
    #[Route('/{id}/copy-to-space', name: '_copy_to_space', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function copyToSpace(int $id, Request $request): JsonResponse
    {
        $source = $this->readable($id);
        $payload = $this->decodeJson($request);

        $spaceId = $payload['spaceId'] ?? null;
        $space = is_int($spaceId) || (is_string($spaceId) && is_numeric($spaceId)) ? $this->spaces->find((int) $spaceId) : null;
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
            return $this->jsonInvalidInput(['title' => 'backend.studio.deliverables.errors.title_too_long']);
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

        $this->manager->delete($deliverable);

        return $this->jsonSuccess($this->viewBuilder->lists());
    }

    /** La page telle que la lira celui qui reçoit le lien. */
    #[Route('/{id}/preview', name: '_preview', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function preview(int $id): Response
    {
        $deliverable = $this->readable($id);

        return $this->privately($this->renderer->render(
            $deliverable,
            $this->generateUrl('backend_studio_deliverables_edit', ['id' => $deliverable->getId()]),
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
        return $this->jsonSuccess($this->linksView->payload($this->readable($id)));
    }

    #[Route('/{id}/links/create', name: '_links_create', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function createLink(int $id, Request $request): JsonResponse
    {
        $deliverable = $this->readable($id);
        if (!$this->access->canShare($deliverable)) {
            return $this->jsonForbidden();
        }

        $this->linkIssuer->issue($deliverable, $this->decodeJson($request));

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

    /** Un livrable de Studio que la personne peut lire, ou 404. */
    private function readable(int $id): DeliverableInterface
    {
        $deliverable = $this->deliverables->findStandalone($id);
        if (!$deliverable instanceof DeliverableInterface || !$this->access->canRead($deliverable)) {
            throw new NotFoundHttpException();
        }

        return $deliverable;
    }
}
