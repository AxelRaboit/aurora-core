<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\View;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableCategoryRepository;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Module\Studio\Deliverable\Security\DeliverableAccess;
use Aurora\Module\Studio\Deliverable\Serializer\DeliverableSerializer;
use Aurora\Module\Studio\Deliverable\Service\DeliverablePageRenderer;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_filter;
use function array_map;
use function array_values;

/**
 * Ce que reçoivent les écrans des livrables de Studio, ceux qui ne sont
 * rattachés à aucun espace : la liste, avec ses deux rayons, et l'éditeur.
 */
final readonly class DeliverablesViewBuilder
{
    public function __construct(
        private DeliverableRepository $deliverables,
        private DeliverableSerializer $serializer,
        private DeliverableAccess $access,
        private UrlGeneratorInterface $urlGenerator,
        private PathTemplateGenerator $pathTemplates,
        private LocaleContextInterface $localeContext,
        private DeliverableCategoryRepository $categories,
    ) {}

    /**
     * La page « Livrables » de Studio.
     *
     * @return array<string, mixed>
     */
    public function index(): array
    {
        $template = fn (string $action): string => $this->pathTemplates->generate('backend_studio_deliverables_'.$action, ['id' => '__id__']);

        return [
            ...$this->lists(),
            'canCreate' => $this->access->canCreate(),
            'listsPath' => $this->urlGenerator->generate('backend_studio_deliverables_lists'),
            'createPath' => $this->urlGenerator->generate('backend_studio_deliverables_create'),
            'scopePathTemplate' => $template('scope'),
            'duplicatePathTemplate' => $template('duplicate'),
            'deletePathTemplate' => $template('delete'),
            'linksPathTemplate' => $template('links'),
            'copyToSpacePathTemplate' => $template('copy_to_space'),
            'copyTargets' => $this->copyTargets(),
            ...$this->categoriesPayload(),
            'canManageCategories' => $this->access->canManageCategories(),
            'categoryCreatePath' => $this->urlGenerator->generate('backend_studio_deliverables_category_create'),
            'categoryUpdatePathTemplate' => $template('category_update'),
            'categoryDeletePathTemplate' => $template('category_delete'),
            'categoryReorderPath' => $this->urlGenerator->generate('backend_studio_deliverables_category_reorder'),
        ];
    }

    /**
     * Les catégories, et les deux rayons qui les affichent : renommer ou
     * supprimer une catégorie change les cartes.
     *
     * @return array{categories: list<array<string, mixed>>, personal: list<array<string, mixed>>, shared: list<array<string, mixed>>}
     */
    public function categoriesPayload(): array
    {
        return [
            'categories' => $this->categoryList(),
            ...$this->lists(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function categoryList(): array
    {
        return array_values(array_filter(array_map($this->serializer->category(...), $this->categories->findOrdered())));
    }

    /**
     * Les deux rayons, chaque ligne avec les gestes que la personne y a.
     *
     * @return array{personal: list<array<string, mixed>>, shared: list<array<string, mixed>>}
     */
    public function lists(): array
    {
        $user = $this->access->user();

        return [
            'personal' => $user instanceof CoreUserInterface ? array_map($this->row(...), $this->deliverables->findPersonalFor($user)) : [],
            'shared' => array_map($this->row(...), $this->deliverables->findShared()),
        ];
    }

    /** @return array<string, mixed> */
    public function row(DeliverableInterface $deliverable): array
    {
        return [
            ...$this->serializer->row($deliverable),
            ...$this->permissions($deliverable),
        ];
    }

    /**
     * L'éditeur d'un livrable de Studio.
     *
     * @return array<string, mixed>
     */
    public function editorView(DeliverableInterface $deliverable): array
    {
        $params = ['id' => $deliverable->getId()];
        $route = fn (string $action): string => $this->urlGenerator->generate('backend_studio_deliverables_'.$action, $params);

        return [
            'deliverable' => $this->serializer->editor($deliverable),
            ...$this->permissions($deliverable),
            'ownerName' => $deliverable->getOwner()?->getName(),
            'locales' => $this->localeContext->getActiveLocales(),
            'hiddenZoneTypes' => DeliverablePageRenderer::HIDDEN_ZONE_TYPES,
            'deliverablesPath' => $this->urlGenerator->generate('backend_studio_deliverables').'?scope='.$deliverable->getScope()->value,
            'updatePath' => $route('update'),
            'previewPath' => $route('preview'),
            'gridPreviewPath' => $this->urlGenerator->generate('backend_studio_deliverables_grid_preview'),
            'bannerPreviewPath' => $this->urlGenerator->generate('backend_studio_deliverables_banner_preview'),
            'linksPath' => $route('links'),
            'duplicatePath' => $route('duplicate'),
            'deletePath' => $route('delete'),
            'copyToSpacePath' => $route('copy_to_space'),
            'copyTargets' => $this->copyTargets(),
            'categories' => $this->categoryList(),
        ];
    }

    /**
     * Les espaces où déposer une copie, pour le sélecteur : vide quand la
     * personne n'écrit dans aucun, et le geste ne s'affiche pas.
     *
     * @return list<array{id: int|null, name: string, customer: string}>
     */
    private function copyTargets(): array
    {
        return array_map(
            static fn (CustomerSpaceInterface $space): array => [
                'id' => $space->getId(),
                'name' => $space->getName(),
                'customer' => $space->getCustomer()->getLegalName(),
            ],
            $this->access->spacesToCopyInto(),
        );
    }

    /** @return array{canEdit: bool, canShare: bool, canDelete: bool, canChangeScope: bool, canDuplicate: bool} */
    private function permissions(DeliverableInterface $deliverable): array
    {
        return [
            'canEdit' => $this->access->canWrite($deliverable),
            'canShare' => $this->access->canShare($deliverable),
            'canDelete' => $this->access->canDelete($deliverable),
            'canChangeScope' => $this->access->canChangeScope($deliverable),
            // Copier, c'est créer : la copie est à qui la fait.
            'canDuplicate' => $this->access->canCreate(),
        ];
    }
}
