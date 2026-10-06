<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\View;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Serializer\CustomerSpaceSerializerInterface;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Module\Studio\Deliverable\Security\DeliverableAccess;
use Aurora\Module\Studio\Deliverable\Serializer\DeliverableSerializer;
use Aurora\Module\Studio\Deliverable\Service\DeliverablePageRenderer;
use Aurora\Module\Studio\StudioContext;
use LogicException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_column;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;

/**
 * What the screens of a space's deliverables receive: its tab, and the
 * editor of one of its deliverables. Studio deliverables have their own, see
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
        private DeliverableAccess $access,
        private DocumentRepository $documents,
        private StudioContext $studioContext,
    ) {}

    /**
     * A space's "Livrables" tab.
     *
     * @return array<string, mixed>
     */
    public function view(CustomerSpaceInterface $space): array
    {
        $id = $space->getId();
        $canAdd = $this->security->isGranted('studio.spaces.edit') && !$space->isArchived();

        return [
            'deliverables' => $this->rows($space),
            'canEditDeliverables' => $this->security->isGranted('studio.spaces.edit'),
            'canShareDeliverables' => $this->security->isGranted(DeliverableAccess::SPACE_SHARE),
            // True when the space still receives deliverables: an archive no
            // longer does, and the list no longer offers to create or duplicate.
            'canAddDeliverables' => $canAdd,
            'deliverableListPath' => $this->urlGenerator->generate('workspace_space_deliverables_lists', ['id' => $id]),
            'deliverableCreatePath' => $this->urlGenerator->generate('workspace_space_deliverables_create', ['id' => $id]),
            // A pasted text that becomes a presentation, as in Studio.
            'deliverableImportPath' => $this->urlGenerator->generate('workspace_space_deliverables_import', ['id' => $id]),
            // « Partir d'un modèle »: the Studio templates the reader may read,
            // pages and presentations; the modal filters by format. Only read
            // for whoever may create here: a space page loads on every tab.
            'deliverableTemplates' => $canAdd ? $this->templates() : [],
            'deliverableVisibilityPathTemplate' => $this->pathTemplates->generate('workspace_space_deliverables_visibility', ['id' => $id, 'deliverableId' => '__id__']),
            'deliverableDuplicatePathTemplate' => $this->pathTemplates->generate('workspace_space_deliverables_duplicate', ['id' => $id, 'deliverableId' => '__id__']),
            'deliverableDeletePathTemplate' => $this->pathTemplates->generate('workspace_space_deliverables_delete', ['id' => $id, 'deliverableId' => '__id__']),
            // The reading links, from the list as from the editor: creating
            // one for a recipient should not mean opening the document first.
            'deliverableLinksPathTemplate' => $this->pathTemplates->generate('workspace_space_deliverables_links', ['id' => $id, 'deliverableId' => '__id__']),
            // Empty when the person cannot create a Studio deliverable: the
            // "Copier dans Studio" action is not shown.
            'deliverableCopyToStudioPathTemplate' => $this->access->canCopyToStudio()
                ? $this->pathTemplates->generate('workspace_space_deliverables_copy_to_studio', ['id' => $id, 'deliverableId' => '__id__'])
                : '',
        ];
    }

    /**
     * The Studio templates a space deliverable may start from: those the
     * reader may read (a colleague's personal template is not one), with the
     * Deliverables module on. Empty otherwise, and the create modal offers
     * none: one starts from a blank page or an empty presentation.
     *
     * The shape of a list row, cut down to what the picker reads.
     *
     * @return list<array{id: int|null, title: string, format: string, template: true, category: array<string, mixed>|null}>
     */
    public function templates(): array
    {
        if (!$this->studioContext->areDeliverablesEnabled() || !$this->security->isGranted(DeliverableAccess::VIEW)) {
            return [];
        }

        return array_values(array_map(
            fn (DeliverableInterface $template): array => [
                'id' => $template->getId(),
                'title' => $template->getTitle(),
                'format' => $template->getFormat()->value,
                'template' => true,
                'category' => $this->serializer->category($template->getCategory()),
            ],
            array_filter($this->deliverables->findLiveStandaloneTemplates(), $this->access->canRead(...)),
        ));
    }

    /**
     * The tab's rows, read without the deliverables' bodies: a space page
     * carries all of them on every opening, whatever the tab.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(CustomerSpaceInterface $space): array
    {
        $rows = $this->deliverables->findRowsForSpace($space);

        // The images of every row in one query, not one per row.
        $ids = array_values(array_unique(array_filter(array_column($rows, 'thumbnailId'))));
        $thumbnails = [];
        foreach ([] === $ids ? [] : $this->documents->findBy(['id' => $ids]) as $document) {
            $thumbnails[(int) $document->getId()] = $document;
        }

        return array_map(
            fn (array $row): array => $this->serializer->spaceRow($row, $space, null === $row['thumbnailId'] ? null : ($thumbnails[$row['thumbnailId']] ?? null)),
            $rows,
        );
    }

    /**
     * A space deliverable's editor.
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
            // The whole space: the workspace shell draws its header from it
            // (pill, name, client, switching to other spaces).
            'space' => $this->spaceSerializer->serialize($space),
            'locales' => $this->localeContext->getActiveLocales(),
            'hiddenZoneTypes' => DeliverablePageRenderer::HIDDEN_ZONE_TYPES,
            'canEdit' => $canEdit,
            // Giving a reading address is the right to share the space, not
            // the right to edit it.
            'canShare' => $this->security->isGranted(DeliverableAccess::SPACE_SHARE),
            'canDelete' => $canEdit,
            // Duplicating adds a deliverable to the space: not in an archive.
            'canDuplicate' => $this->access->canAddTo($space),
            'deliverablesPath' => $this->urlGenerator->generate('workspace_space_content', ['id' => $space->getId()]).'?view=deliverables',
            // What the space shell expects for its header and its two tabs.
            'backPath' => $this->urlGenerator->generate('suite_studio_spaces'),
            'boardPath' => $this->urlGenerator->generate('workspace_space_content', ['id' => $space->getId()]),
            'accessPath' => $this->urlGenerator->generate('workspace_space_access', ['id' => $space->getId()]),
            'updatePath' => $this->urlGenerator->generate('workspace_space_deliverables_update', $params),
            'previewPath' => $this->urlGenerator->generate('workspace_space_deliverables_preview', $params),
            'gridPreviewPath' => $this->urlGenerator->generate('workspace_space_deliverables_grid_preview', ['id' => $space->getId()]),
            'bannerPreviewPath' => $this->urlGenerator->generate('workspace_space_deliverables_banner_preview', ['id' => $space->getId()]),
            'linksPath' => $this->urlGenerator->generate('workspace_space_deliverables_links', $params),
            'duplicatePath' => $this->urlGenerator->generate('workspace_space_deliverables_duplicate', $params),
            'deletePath' => $this->urlGenerator->generate('workspace_space_deliverables_delete', $params),
            'copyToStudioPath' => $this->access->canCopyToStudio()
                ? $this->urlGenerator->generate('workspace_space_deliverables_copy_to_studio', $params)
                : '',
        ];
    }
}
