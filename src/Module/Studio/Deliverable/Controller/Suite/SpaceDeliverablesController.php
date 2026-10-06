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
use Aurora\Module\Studio\Deliverable\Manager\DeliverableManager;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Module\Studio\Deliverable\Security\DeliverableAccess;
use Aurora\Module\Studio\Deliverable\Serializer\DeliverableSerializer;
use Aurora\Module\Studio\Deliverable\Service\DeliverableEditorPreviews;
use Aurora\Module\Studio\Deliverable\Service\DeliverableLinkIssuer;
use Aurora\Module\Studio\Deliverable\Service\DeliverablePageRenderer;
use Aurora\Module\Studio\Deliverable\Service\DeliverableReadiness;
use Aurora\Module\Studio\Deliverable\View\DeliverableLinksView;
use Aurora\Module\Studio\Deliverable\View\SpaceDeliverablesViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_string;
use function mb_strlen;
use function mb_substr;
use function mb_trim;

/**
 * Les livrables d'un espace, côté studio.
 *
 * Tout passe par l'espace dans l'adresse : l'abonné qui garde les espaces
 * ({@see SpaceVisibilitySubscriber})
 * refuse un espace dont on n'est pas membre, et chaque livrable reçu est
 * vérifié contre cet espace-là. Un identifiant d'un autre espace répond le
 * même 404 qu'un identifiant qui n'existe pas.
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
    ) {}

    /** Les lignes de l'onglet telles qu'elles sont maintenant, pour rafraîchir une liste périmée. */
    #[Route('/lists', name: '_lists', methods: [HttpMethodEnum::Get->value])]
    public function lists(CustomerSpace $space): JsonResponse
    {
        return $this->jsonSuccess(['deliverables' => $this->viewBuilder->rows($space)]);
    }

    /** Un titre, et on arrive dans l'éditeur. */
    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function create(CustomerSpace $space, Request $request): JsonResponse
    {
        // Les archives n'en reçoivent plus, comme elles ne reçoivent plus de copie.
        if (!$this->access->canAddTo($space)) {
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

        // À qui le crée, comme la copie d'un modèle : un livrable d'espace n'a
        // plus un auteur nul, un auteur copié et l'ancien auteur selon la voie.
        $deliverable = $this->manager->create($space, $title, $this->access->user());

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

        // L'éditeur enregistre tout d'un coup, la case « Visible par le
        // client » comprise : la changer demande le droit de partager
        // l'espace, celui du bouton de la liste.
        if (!$this->clientVisibility->allowsChange($deliverable->isVisibleToClient(), true === ($payload['visibleToClient'] ?? false))) {
            return $this->jsonForbidden();
        }

        $errors = $this->manager->update($deliverable, $payload);

        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        return $this->jsonSuccess(['deliverable' => $this->viewBuilder->editorView($deliverable)['deliverable']]);
    }

    /** Montré ou caché au client, depuis la liste : le droit de partager l'espace. */
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

        // Ouvrir au client est le moment où un modèle mal rempli lui parvient :
        // l'éditeur prévient, la liste doit le faire aussi. Refusé tant que
        // l'auteur n'a pas dit qu'il le sait (`confirm`), avec ce qui reste.
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
     * Une copie dans Studio, pour garder ce livrable comme modèle : elle
     * arrive dans « Mes livrables », et l'on ouvre son éditeur.
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
     * La page telle que le client la lira, ouverte par le studio.
     *
     * Visible ou non au client : c'est ce qui permet de relire avant d'ouvrir.
     */
    #[Route('/{deliverableId}/preview', name: '_preview', requirements: ['deliverableId' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function preview(
        CustomerSpace $space,
        int $deliverableId,
        Request $request,
    ): Response {
        $deliverable = $this->owned($space, $deliverableId);
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
     * L'aperçu de la grille pendant qu'on la compose : le même gabarit de
     * zones que la page, rendu à partir de ce que l'éditeur tient.
     */
    #[Route('/grid-preview', name: '_grid_preview', methods: [HttpMethodEnum::Post->value])]
    public function gridPreview(CustomerSpace $space, Request $request): JsonResponse
    {
        return $this->json(['success' => true, 'html' => $this->previews->grid($this->decodeJson($request))]);
    }

    /** L'aperçu d'un bloc d'entête posé dans la grille. */
    #[Route('/banner-preview', name: '_banner_preview', methods: [HttpMethodEnum::Post->value])]
    public function bannerPreview(CustomerSpace $space, Request $request): JsonResponse
    {
        return $this->json(['success' => true, 'html' => $this->previews->banner($this->decodeJson($request))]);
    }

    /**
     * Les liens de lecture, adresses comprises : sous le droit de partager
     * l'espace, comme l'accès de l'espace lui-même, et pas sous celui de le
     * modifier. Les lire, c'est pouvoir les transmettre.
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
     * Une adresse de plus : un intitulé pour s'y retrouver, une expiration et
     * un mot de passe au choix.
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

    /** Révoquer date la ligne ; elle n'est jamais supprimée. */
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

    /** Masquer de la liste un lien retiré ou expiré : sa ligne reste, un lien vivant ne se masque pas. */
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

    /** Supprimer une adresse que personne n'a jamais ouverte ; une adresse déjà ouverte se révoque seulement. */
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
     * Le livrable de cet espace, ou 404 : l'identifiant d'un autre espace, ou
     * d'un livrable de Studio, répond comme un identifiant qui n'existe pas.
     *
     * Résolu par le dépôt et rendu en interface, pas par un `MapEntity` sur la
     * classe du cœur : un projet qui substitue l'entité garde ses routes.
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
