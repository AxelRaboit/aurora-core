<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\View;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Serializer\CustomerSpaceSerializerInterface;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Module\Studio\Deliverable\Serializer\DeliverableSerializer;
use Aurora\Module\Studio\Deliverable\Service\DeliverablePageRenderer;
use LogicException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_map;

/**
 * Ce que reçoivent les écrans des livrables d'un espace : son onglet, et
 * l'éditeur d'un de ses livrables. Les livrables de Studio ont les leurs, cf.
 * {@see DeliverablesViewBuilder}.
 */
final readonly class SpaceDeliverablesViewBuilder
{
    public function __construct(
        private DeliverableRepository $deliverables,
        private DeliverableSerializer $serializer,
        private UrlGeneratorInterface $urlGenerator,
        private PathTemplateGenerator $pathTemplates,
        private LocaleContextInterface $localeContext,
        private Security $security,
        private CustomerSpaceSerializerInterface $spaceSerializer,
    ) {}

    /**
     * L'onglet « Livrables » d'un espace.
     *
     * @return array<string, mixed>
     */
    public function view(CustomerSpaceInterface $space): array
    {
        $id = $space->getId();

        return [
            'deliverables' => $this->rows($space),
            'canEditDeliverables' => $this->security->isGranted('studio.spaces.edit'),
            'deliverableCreatePath' => $this->urlGenerator->generate('workspace_space_deliverables_create', ['id' => $id]),
            'deliverableVisibilityPathTemplate' => $this->pathTemplates->generate('workspace_space_deliverables_visibility', ['id' => $id, 'deliverableId' => '__id__']),
            'deliverableDuplicatePathTemplate' => $this->pathTemplates->generate('workspace_space_deliverables_duplicate', ['id' => $id, 'deliverableId' => '__id__']),
            'deliverableDeletePathTemplate' => $this->pathTemplates->generate('workspace_space_deliverables_delete', ['id' => $id, 'deliverableId' => '__id__']),
            // The reading links, from the list as from the editor: creating
            // one for a recipient should not mean opening the document first.
            'deliverableLinksPathTemplate' => $this->pathTemplates->generate('workspace_space_deliverables_links', ['id' => $id, 'deliverableId' => '__id__']),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function rows(CustomerSpaceInterface $space): array
    {
        return array_map($this->serializer->row(...), $this->deliverables->findForSpace($space));
    }

    /**
     * L'éditeur d'un livrable d'espace.
     *
     * @return array<string, mixed>
     */
    public function editorView(DeliverableInterface $deliverable): array
    {
        $space = $deliverable->getSpace();
        if (!$space instanceof CustomerSpaceInterface) {
            throw new LogicException('A deliverable without a space opens in Studio, not in a space.');
        }

        $params = ['id' => $space->getId(), 'deliverableId' => $deliverable->getId()];
        $canEdit = $this->security->isGranted('studio.spaces.edit');

        return [
            'deliverable' => $this->serializer->editor($deliverable),
            // L'espace entier : la coquille de l'espace de travail en dessine
            // l'en-tête (pastille, nom, client, passage aux autres espaces).
            'space' => $this->spaceSerializer->serialize($space),
            'locales' => $this->localeContext->getActiveLocales(),
            'hiddenZoneTypes' => DeliverablePageRenderer::HIDDEN_ZONE_TYPES,
            'canEdit' => $canEdit,
            'canShare' => $canEdit,
            'canDelete' => $canEdit,
            'deliverablesPath' => $this->urlGenerator->generate('workspace_space_content', ['id' => $space->getId()]).'?view=deliverables',
            // Ce que la coquille de l'espace attend pour son en-tête et ses
            // deux onglets.
            'backPath' => $this->urlGenerator->generate('backend_studio_spaces'),
            'boardPath' => $this->urlGenerator->generate('workspace_space_content', ['id' => $space->getId()]),
            'accessPath' => $this->urlGenerator->generate('workspace_space_access', ['id' => $space->getId()]),
            'updatePath' => $this->urlGenerator->generate('workspace_space_deliverables_update', $params),
            'previewPath' => $this->urlGenerator->generate('workspace_space_deliverables_preview', $params),
            'gridPreviewPath' => $this->urlGenerator->generate('workspace_space_deliverables_grid_preview', ['id' => $space->getId()]),
            'bannerPreviewPath' => $this->urlGenerator->generate('workspace_space_deliverables_banner_preview', ['id' => $space->getId()]),
            'linksPath' => $this->urlGenerator->generate('workspace_space_deliverables_links', $params),
            'duplicatePath' => $this->urlGenerator->generate('workspace_space_deliverables_duplicate', $params),
            'deletePath' => $this->urlGenerator->generate('workspace_space_deliverables_delete', $params),
        ];
    }
}
