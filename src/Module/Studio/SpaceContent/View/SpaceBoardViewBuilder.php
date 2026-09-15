<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\View;

use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Serializer\CustomerSpaceSerializerInterface;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use Aurora\Module\Studio\SpaceContent\Serializer\SpaceContentColumnSerializerInterface;
use Aurora\Module\Studio\SpaceContent\Serializer\SpaceContentItemSerializerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class SpaceBoardViewBuilder
{
    public function __construct(
        private SpaceContentColumnRepository $columnRepository,
        private SpaceContentItemRepository $itemRepository,
        private SpaceContentColumnSerializerInterface $columnSerializer,
        private SpaceContentItemSerializerInterface $itemSerializer,
        private CustomerSpaceSerializerInterface $spaceSerializer,
        private PathTemplateGenerator $pathTemplates,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /** @return array<string, mixed> */
    public function boardView(CustomerSpaceInterface $space): array
    {
        return [
            'space' => $this->spaceSerializer->serialize($space),
            'columns' => $this->columns($space),
            'items' => $this->items($space),
            'backPath' => $this->urlGenerator->generate('backend_studio_spaces'),
            'itemCreatePath' => $this->urlGenerator->generate('workspace_space_content_item_create', ['id' => $space->getId()]),
            'itemUpdatePath' => $this->pathTemplates->generate('workspace_space_content_item_update', ['id' => $space->getId(), 'itemId' => '__id__']),
            'itemDeletePath' => $this->pathTemplates->generate('workspace_space_content_item_delete', ['id' => $space->getId(), 'itemId' => '__id__']),
            'itemReorderPath' => $this->urlGenerator->generate('workspace_space_content_item_reorder', ['id' => $space->getId()]),
            'columnCreatePath' => $this->urlGenerator->generate('workspace_space_content_column_create', ['id' => $space->getId()]),
            'columnUpdatePath' => $this->pathTemplates->generate('workspace_space_content_column_update', ['id' => $space->getId(), 'columnId' => '__id__']),
            'columnDeletePath' => $this->pathTemplates->generate('workspace_space_content_column_delete', ['id' => $space->getId(), 'columnId' => '__id__']),
            'columnReorderPath' => $this->urlGenerator->generate('workspace_space_content_column_reorder', ['id' => $space->getId()]),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function columns(CustomerSpaceInterface $space): array
    {
        return array_map(
            $this->columnSerializer->serialize(...),
            $this->columnRepository->findForSpace($space),
        );
    }

    /** @return list<array<string, mixed>> */
    public function items(CustomerSpaceInterface $space): array
    {
        return array_map(
            $this->itemSerializer->serialize(...),
            $this->itemRepository->findForSpace($space),
        );
    }

    /**
     * What every write answers with.
     *
     * The whole board rather than the row that changed: a drag rewrites the
     * positions of a column, a column deletion renumbers the rest, and a page
     * that patched its own copy from a single row would drift from the server
     * within three gestures. The board is small enough that sending it back is
     * cheaper than being subtly wrong.
     *
     * @return array<string, mixed>
     */
    public function boardPayload(CustomerSpaceInterface $space): array
    {
        return [
            'success' => true,
            'columns' => $this->columns($space),
            'items' => $this->items($space),
        ];
    }
}
