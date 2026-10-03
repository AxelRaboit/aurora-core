<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Http\PrivateAddressResponseTrait;
use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Module\Configuration\Theme\Service\ThemeResolver;
use Aurora\Module\Configuration\Theme\Service\ThemeStyleRenderer;
use Aurora\Module\Editorial\Post\Banner\BannerViewBuilder;
use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Module\Studio\CustomerSpace\Controller\SpaceOwnershipTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\EventSubscriber\SpaceVisibilitySubscriber;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableLink;
use Aurora\Module\Studio\Deliverable\Manager\DeliverableManager;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableLinkRepository;
use Aurora\Module\Studio\Deliverable\Service\DeliverablePageRenderer;
use Aurora\Module\Studio\Deliverable\View\SpaceDeliverablesViewBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function mb_trim;
use function min;
use function password_hash;
use function sprintf;

use const PASSWORD_DEFAULT;

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

    /** Un an : au-delà, une date d'expiration ne protège plus grand-chose. */
    private const int MAX_EXPIRY_DAYS = 365;

    /** Le sélecteur de l'aperçu du bloc d'entête, cf. PostBannerPanel.vue. */
    private const string BANNER_PREVIEW_SELECTOR = '.aurora-banner-preview[data-theme]';

    public function __construct(
        private readonly DeliverableManager $manager,
        private readonly SpaceDeliverablesViewBuilder $viewBuilder,
        private readonly DeliverablePageRenderer $renderer,
        private readonly DeliverableLinkRepository $links,
        private readonly GridViewBuilder $gridViewBuilder,
        private readonly BannerViewBuilder $bannerViewBuilder,
        private readonly ThemeResolver $themeResolver,
        private readonly ThemeStyleRenderer $themeStyles,
        private readonly LocaleContextInterface $localeContext,
        private readonly EntityManagerInterface $entityManager,
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
        $this->assertOwned($space, $deliverable->getSpace()->getId());

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
        $this->assertOwned($space, $deliverable->getSpace()->getId());

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
        $this->assertOwned($space, $deliverable->getSpace()->getId());

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
        $this->assertOwned($space, $deliverable->getSpace()->getId());

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
        $this->assertOwned($space, $deliverable->getSpace()->getId());

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
        $this->assertOwned($space, $deliverable->getSpace()->getId());

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
        $payload = $this->decodeJson($request);
        $layout = is_array($payload['layout'] ?? null) ? $payload['layout'] : [];
        $content = is_array($payload['content'] ?? null) ? $payload['content'] : [];
        $locale = $this->locale($payload['locale'] ?? null);

        return $this->json([
            'success' => true,
            'html' => $this->renderView(
                $this->themeResolver->resolve('editorial/post/_grid'),
                ['grid' => $this->gridViewBuilder->buildForEditor($layout, $content, $locale), 'locale' => $locale],
            ),
        ]);
    }

    /** L'aperçu d'un bloc d'entête posé dans la grille. */
    #[Route('/banner-preview', name: '_banner_preview', methods: [HttpMethodEnum::Post->value])]
    public function bannerPreview(CustomerSpace $space, Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $layout = is_array($payload['layout'] ?? null) ? $payload['layout'] : [];
        $texts = is_array($payload['texts'] ?? null) ? $payload['texts'] : [];
        $slide = is_int($payload['slide'] ?? null) ? $payload['slide'] : 0;

        return $this->json([
            'success' => true,
            'html' => '<style>'.$this->themeStyles->previewSurfaceCss(self::BANNER_PREVIEW_SELECTOR).'</style>'
                .$this->renderView(
                    $this->themeResolver->resolve('editorial/post/_banner'),
                    ['banner' => $this->bannerViewBuilder->buildForEditor($layout, $texts, max(0, $slide))],
                ),
        ]);
    }

    #[Route('/{deliverableId}/links', name: '_links', requirements: ['deliverableId' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function links(
        CustomerSpace $space,
        #[MapEntity(id: 'deliverableId')]
        Deliverable $deliverable,
    ): JsonResponse {
        $this->assertOwned($space, $deliverable->getSpace()->getId());

        return $this->jsonSuccess($this->viewBuilder->linksPayload($deliverable));
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
        $this->assertOwned($space, $deliverable->getSpace()->getId());

        $payload = $this->decodeJson($request);

        $link = new DeliverableLink($deliverable);
        $link->setLabel(is_string($payload['label'] ?? null) ? mb_substr(mb_trim($payload['label']), 0, 120) : '');

        $days = is_int($payload['expiresInDays'] ?? null) ? $payload['expiresInDays'] : null;
        if (null !== $days && $days > 0) {
            $link->setExpiresAt(new DateTimeImmutable(sprintf('+%d days', min($days, self::MAX_EXPIRY_DAYS))));
        }

        // `password_hash`, comme pour une présentation : c'est une phrase
        // choisie par quelqu'un, et les gens réutilisent leurs phrases.
        $password = is_string($payload['password'] ?? null) ? mb_trim($payload['password']) : '';
        if ('' !== $password) {
            $link->setPasswordHash(password_hash($password, PASSWORD_DEFAULT));
        }

        $this->entityManager->persist($link);
        $this->entityManager->flush();

        return $this->jsonSuccess($this->viewBuilder->linksPayload($deliverable));
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
        $this->assertOwned($space, $deliverable->getSpace()->getId());

        $link = $this->links->find($linkId);

        // Vérifié contre le livrable de l'adresse : le lien d'un autre
        // livrable ne se révoque pas par celui-ci.
        if (null === $link || $link->getDeliverable()->getId() !== $deliverable->getId()) {
            return $this->jsonNotFound();
        }

        $link->revoke(new DateTimeImmutable());
        $this->entityManager->flush();

        return $this->jsonSuccess($this->viewBuilder->linksPayload($deliverable));
    }

    private function locale(mixed $value): string
    {
        return is_string($value) && in_array($value, $this->localeContext->getActiveLocales(), true)
            ? $value
            : $this->localeContext->getDefaultLocale();
    }
}
