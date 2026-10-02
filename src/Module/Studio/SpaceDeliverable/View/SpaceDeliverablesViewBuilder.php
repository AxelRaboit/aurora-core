<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceDeliverable\View;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Editorial\Post\Service\PostPictures;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Serializer\CustomerSpaceSerializerInterface;
use Aurora\Module\Studio\SpaceDeliverable\Entity\SpaceDeliverableInterface;
use Aurora\Module\Studio\SpaceDeliverable\Repository\SpaceDeliverableLinkRepository;
use Aurora\Module\Studio\SpaceDeliverable\Repository\SpaceDeliverableRepository;
use Aurora\Module\Studio\SpaceDeliverable\Serializer\SpaceDeliverableSerializer;
use Aurora\Module\Studio\SpaceDeliverable\Service\DeliverablePageRenderer;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_map;
use function sprintf;

/**
 * Ce que les écrans des livrables reçoivent : l'onglet d'un espace, et
 * l'éditeur d'un livrable.
 */
final readonly class SpaceDeliverablesViewBuilder
{
    public function __construct(
        private SpaceDeliverableRepository $deliverables,
        private SpaceDeliverableLinkRepository $links,
        private SpaceDeliverableSerializer $serializer,
        private UrlGeneratorInterface $urlGenerator,
        private PathTemplateGenerator $pathTemplates,
        private LocaleContextInterface $localeContext,
        private PostPictures $pictures,
        private DocumentRepository $documents,
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
        ];
    }

    /** @return list<array<string, mixed>> */
    public function rows(CustomerSpaceInterface $space): array
    {
        return array_map($this->serializer->row(...), $this->deliverables->findForSpace($space));
    }

    /**
     * L'éditeur d'un livrable.
     *
     * @return array<string, mixed>
     */
    public function editorView(SpaceDeliverableInterface $deliverable): array
    {
        $space = $deliverable->getSpace();
        $params = ['id' => $space->getId(), 'deliverableId' => $deliverable->getId()];

        return [
            'deliverable' => $this->serializer->editor($deliverable),
            // L'espace entier : la coquille de l'espace de travail en dessine
            // l'en-tête (pastille, nom, client, passage aux autres espaces).
            'space' => $this->spaceSerializer->serialize($space),
            'locales' => $this->localeContext->getActiveLocales(),
            'hiddenZoneTypes' => DeliverablePageRenderer::HIDDEN_ZONE_TYPES,
            'canEdit' => $this->security->isGranted('studio.spaces.edit'),
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

    /**
     * Les liens de lecture d'un livrable, et les images que leurs lecteurs ne
     * verront pas.
     *
     * @return array<string, mixed>
     */
    public function linksPayload(SpaceDeliverableInterface $deliverable): array
    {
        return [
            // Rien n'est un brouillon ici : un lien ouvre toujours la page.
            'readable' => true,
            'withheldPictures' => $this->withheldPictures($deliverable),
            'links' => array_map($this->serializer->link(...), $this->links->findForDeliverable($deliverable)),
        ];
    }

    /**
     * Les documents de la médiathèque que la page utilise sans qu'ils soient
     * publiés : un lecteur hors du back-office ne les verra pas, et mieux vaut
     * le dire avant d'envoyer l'adresse.
     *
     * @return list<array{id: int, name: string}>
     */
    private function withheldPictures(SpaceDeliverableInterface $deliverable): array
    {
        $ids = $this->pictures->idsInGridLayout($deliverable->getGridLayout());

        if ([] === $ids) {
            return [];
        }

        $withheld = [];
        foreach ($this->documents->findBy(['id' => $ids]) as $document) {
            if (DocumentStatusEnum::Published === $document->getStatus()) {
                continue;
            }

            $withheld[] = [
                'id' => (int) $document->getId(),
                'name' => $document->getOriginalName() ?? sprintf('#%d', $document->getId()),
            ];
        }

        return $withheld;
    }
}
