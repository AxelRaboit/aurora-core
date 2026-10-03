<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Http\PrivateAddressResponseTrait;
use Aurora\Module\Studio\CustomerSpace\Controller\SpaceOwnershipTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\EventSubscriber\SpaceVisibilitySubscriber;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Manager\DeliverableManager;
use Aurora\Module\Studio\Deliverable\Service\DeliverableEditorPreviews;
use Aurora\Module\Studio\Deliverable\Service\DeliverableLinkIssuer;
use Aurora\Module\Studio\Deliverable\Service\DeliverablePageRenderer;
use Aurora\Module\Studio\Deliverable\View\DeliverableLinksView;
use Aurora\Module\Studio\Deliverable\View\SpaceDeliverablesViewBuilder;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
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
        private readonly DeliverableManager $manager,
        private readonly SpaceDeliverablesViewBuilder $viewBuilder,
        private readonly DeliverablePageRenderer $renderer,
        private readonly DeliverableEditorPreviews $previews,
        private readonly DeliverableLinkIssuer $linkIssuer,
        private readonly DeliverableLinksView $linksView,
        private readonly TranslatorInterface $translator,
    ) {}

    /** Un titre, et on arrive dans l'éditeur. */
    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function create(CustomerSpace $space, Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $title = is_string($payload['title'] ?? null) ? mb_trim($payload['title']) : '';

        if ('' === $title) {
            return $this->jsonInvalidInput(['title' => 'backend.studio.deliverables.errors.title_required']);
        }

        if (mb_strlen($title) > DeliverableManager::TITLE_MAX) {
            return $this->jsonInvalidInput(['title' => 'backend.studio.deliverables.errors.title_too_long']);
        }

        $deliverable = $this->manager->create($space, $title);

        return $this->jsonSuccess([
            'editPath' => $this->generateUrl('workspace_space_deliverables_edit', ['id' => $space->getId(), 'deliverableId' => $deliverable->getId()]),
            'deliverables' => $this->viewBuilder->rows($space),
        ]);
    }

    #[Route('/{deliverableId}', name: '_edit', requirements: ['deliverableId' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function edit(
        CustomerSpace $space,
        #[MapEntity(id: 'deliverableId')]
        Deliverable $deliverable,
    ): Response {
        $this->assertOwned($space, $deliverable->getSpace()?->getId());

        return $this->render('@Studio/backend/space-deliverables/edit.html.twig', $this->viewBuilder->editorView($deliverable));
    }

    #[Route('/{deliverableId}/update', name: '_update', requirements: ['deliverableId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function update(
        CustomerSpace $space,
        #[MapEntity(id: 'deliverableId')]
        Deliverable $deliverable,
        Request $request,
    ): JsonResponse {
        $this->assertOwned($space, $deliverable->getSpace()?->getId());

        $errors = $this->manager->update($deliverable, $this->decodeJson($request));

        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        return $this->jsonSuccess(['deliverable' => $this->viewBuilder->editorView($deliverable)['deliverable']]);
    }

    /** Ouvert ou fermé au client, depuis la liste. */
    #[Route('/{deliverableId}/visibility', name: '_visibility', requirements: ['deliverableId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function visibility(
        CustomerSpace $space,
        #[MapEntity(id: 'deliverableId')]
        Deliverable $deliverable,
        Request $request,
    ): JsonResponse {
        $this->assertOwned($space, $deliverable->getSpace()?->getId());

        $this->manager->setVisibleToClient($deliverable, true === ($this->decodeJson($request)['visible'] ?? false));

        return $this->jsonSuccess(['deliverables' => $this->viewBuilder->rows($space)]);
    }

    #[Route('/{deliverableId}/duplicate', name: '_duplicate', requirements: ['deliverableId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function duplicate(
        CustomerSpace $space,
        #[MapEntity(id: 'deliverableId')]
        Deliverable $deliverable,
    ): JsonResponse {
        $this->assertOwned($space, $deliverable->getSpace()?->getId());

        $title = mb_substr(
            $this->translator->trans('backend.studio.deliverables.copy_title', ['%title%' => $deliverable->getTitle()]),
            0,
            DeliverableManager::TITLE_MAX,
        );
        $copy = $this->manager->duplicate($deliverable, $title);

        return $this->jsonSuccess([
            'editPath' => $this->generateUrl('workspace_space_deliverables_edit', ['id' => $space->getId(), 'deliverableId' => $copy->getId()]),
            'deliverables' => $this->viewBuilder->rows($space),
        ]);
    }

    #[Route('/{deliverableId}/delete', name: '_delete', requirements: ['deliverableId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function delete(
        CustomerSpace $space,
        #[MapEntity(id: 'deliverableId')]
        Deliverable $deliverable,
    ): JsonResponse {
        $this->assertOwned($space, $deliverable->getSpace()?->getId());

        $this->manager->delete($deliverable);

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
        #[MapEntity(id: 'deliverableId')]
        Deliverable $deliverable,
    ): Response {
        $this->assertOwned($space, $deliverable->getSpace()?->getId());

        return $this->privately($this->renderer->render(
            $deliverable,
            $this->generateUrl('workspace_space_deliverables_edit', ['id' => $space->getId(), 'deliverableId' => $deliverable->getId()]),
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

    #[Route('/{deliverableId}/links', name: '_links', requirements: ['deliverableId' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function links(
        CustomerSpace $space,
        #[MapEntity(id: 'deliverableId')]
        Deliverable $deliverable,
    ): JsonResponse {
        $this->assertOwned($space, $deliverable->getSpace()?->getId());

        return $this->jsonSuccess($this->linksView->payload($deliverable));
    }

    /**
     * Une adresse de plus : un intitulé pour s'y retrouver, une expiration et
     * un mot de passe au choix.
     */
    #[Route('/{deliverableId}/links/create', name: '_links_create', requirements: ['deliverableId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function createLink(
        CustomerSpace $space,
        #[MapEntity(id: 'deliverableId')]
        Deliverable $deliverable,
        Request $request,
    ): JsonResponse {
        $this->assertOwned($space, $deliverable->getSpace()?->getId());

        $this->linkIssuer->issue($deliverable, $this->decodeJson($request));

        return $this->jsonSuccess($this->linksView->payload($deliverable));
    }

    /** Révoquer date la ligne ; elle n'est jamais supprimée. */
    #[Route('/{deliverableId}/links/{linkId}/revoke', name: '_links_revoke', requirements: ['deliverableId' => '\d+', 'linkId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function revokeLink(
        CustomerSpace $space,
        #[MapEntity(id: 'deliverableId')]
        Deliverable $deliverable,
        int $linkId,
    ): JsonResponse {
        $this->assertOwned($space, $deliverable->getSpace()?->getId());

        if (!$this->linkIssuer->revoke($deliverable, $linkId)) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess($this->linksView->payload($deliverable));
    }
}
