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
#[Route('/suite/studio/deliverables', name: 'suite_studio_deliverables')]
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
        private readonly DeliverableCategoryRepository $categories,
        private readonly DeliverableCategoryManager $categoryManager,
        private readonly PayloadValidator $payloadValidator,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(Request $request): Response
    {
        return $this->render('@Studio/suite/deliverables/index.html.twig', [
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

    /**
     * Un titre et un rayon, et on arrive dans l'éditeur.
     *
     * Le format se choisit ici et nulle part ailleurs : absent, c'est une
     * page. Un diaporama est refusé tant que son éditeur n'est pas branché
     * sur les livrables, cf. {@see DeliverableFormatEnum::isCreatable()} ;
     * la fenêtre de création ne le propose pas encore.
     *
     * Parti d'un modèle (`fromTemplateId`), le livrable en reprend le corps
     * et le format ; la catégorie aussi, sauf si l'envoi en nomme une. Un
     * modèle qu'on ne lit pas, ou qui n'en est plus un, donne un livrable
     * vide plutôt qu'un refus : le sélecteur vient de la liste, et la seule
     * façon d'envoyer un identifiant périmé est un modèle retiré entre
     * l'ouverture de la page et la création, qui ferait perdre le titre tapé.
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

    #[Route('/{id}', name: '_edit', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function edit(int $id): Response
    {
        return $this->render('@Studio/suite/deliverables/edit.html.twig', $this->viewBuilder->editorView($this->readable($id)));
    }

    #[Route('/{id}/update', name: '_update', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function update(int $id, Request $request): JsonResponse
    {
        $deliverable = $this->readable($id);
        if (!$this->access->canWrite($deliverable)) {
            return $this->jsonForbidden();
        }

        $payload = $this->decodeJson($request);

        // Le client ne se nomme qu'avec le droit de voir les clients : sans
        // lui, l'éditeur renvoie celui qu'il a reçu, et il ne compte pas.
        if (!$this->access->canPickCustomer()) {
            unset($payload['customerId']);
        }

        // Avant la validation : dire que le titre est invalide quand la vraie
        // réponse est qu'un collègue a enregistré entre-temps serait faux.
        if ($this->manager->isStale($deliverable, $payload)) {
            return $this->jsonFailure('conflict', HttpStatusEnum::Conflict->value, ['conflict' => true]);
        }

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

        // Strict, au contraire de la création : un corps vide ou une faute de
        // frappe ne doit pas retirer à l'équipe un livrable partagé. « perso »
        // n'est pas la valeur par défaut d'un geste qui retire.
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

        // À la corbeille, pas détruit : il y reste le délai commun, et ses
        // liens de lecture reprennent s'il en sort.
        $this->manager->trash($deliverable);

        return $this->jsonSuccess($this->viewBuilder->lists());
    }

    /**
     * Sortir un livrable de la corbeille, de Studio ou d'un espace : c'est ce
     * que l'écran de la corbeille appelle pour chacun de ses livrables. Le
     * droit est celui de le modifier ; un livrable qu'on ne lit pas répond 404.
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

    /** Détruire pour de bon un livrable de la corbeille : le droit de le supprimer, comme avant la corbeille. */
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
     * Vider la corbeille : seulement ce que la personne lit et a le droit de
     * supprimer, jamais le livrable perso d'un collègue ni celui d'un espace
     * qui n'est pas le sien.
     */
    #[Route('/empty-trash', name: '_empty_trash', methods: [HttpMethodEnum::Post->value])]
    public function emptyTrash(): JsonResponse
    {
        $deleted = 0;
        foreach ($this->deliverables->findAllTrashed() as $deliverable) {
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

    /** La page telle que la lira celui qui reçoit le lien. */
    #[Route('/{id}/preview', name: '_preview', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function preview(int $id, Request $request): Response
    {
        $deliverable = $this->readable($id);
        $print = $request->query->getBoolean('print');

        return $this->privately($this->renderer->render(
            $deliverable,
            $this->generateUrl('suite_studio_deliverables_edit', ['id' => $deliverable->getId()]),
            markPlaceholders: !$print,
            print: $print,
            view: DeliverablePageRenderer::requestedView($request->query->all()['view'] ?? null),
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
        // La liste porte les adresses elles-mêmes, jetons compris : la lire,
        // c'est pouvoir les transmettre. Même droit que d'en créer.
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

    /** Masquer de la liste un lien retiré ou expiré : sa ligne reste, un lien vivant ne se masque pas. */
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

    /** Supprimer une adresse que personne n'a jamais ouverte ; une adresse déjà ouverte se révoque seulement. */
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

        $category = $this->categories->find($id);
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

    /** Ses livrables restent, sans catégorie. */
    #[Route('/categories/{id}/delete', name: '_category_delete', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function deleteCategory(int $id): JsonResponse
    {
        if (!$this->access->canManageCategories()) {
            return $this->jsonForbidden();
        }

        $category = $this->categories->find($id);
        if (!$category instanceof DeliverableCategoryInterface) {
            return $this->jsonNotFound();
        }

        $this->categoryManager->delete($category);

        return $this->jsonSuccess($this->viewBuilder->categoriesPayload());
    }

    /** L'ordre des catégories, tel qu'on l'a rangé dans la fenêtre. */
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

    /** Le modèle dont part un livrable neuf : un livrable de Studio vivant, lisible, et toujours un modèle. */
    private function template(mixed $id): ?DeliverableInterface
    {
        $id = is_int($id) || (is_string($id) && is_numeric($id)) ? (int) $id : null;
        $template = null === $id ? null : $this->deliverables->findStandalone($id);

        return $template instanceof DeliverableInterface && $template->isTemplate() && $this->access->canRead($template) ? $template : null;
    }

    /** Un livrable à la corbeille que la personne peut lire, ou 404. */
    private function trashed(int $id): DeliverableInterface
    {
        $deliverable = $this->deliverables->findTrashed($id);
        if (!$deliverable instanceof DeliverableInterface || !$this->access->canRead($deliverable)) {
            throw new NotFoundHttpException();
        }

        return $deliverable;
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
