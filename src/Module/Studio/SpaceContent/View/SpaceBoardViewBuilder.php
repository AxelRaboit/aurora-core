<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\View;

use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Serializer\CustomerSpaceSerializerInterface;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentAttachmentRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentCommentRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use Aurora\Module\Studio\SpaceContent\Serializer\SpaceContentAttachmentSerializerInterface;
use Aurora\Module\Studio\SpaceContent\Serializer\SpaceContentColumnSerializerInterface;
use Aurora\Module\Studio\SpaceContent\Serializer\SpaceContentCommentSerializerInterface;
use Aurora\Module\Studio\SpaceContent\Serializer\SpaceContentItemSerializerInterface;
use DateTimeImmutable;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class SpaceBoardViewBuilder
{
    public function __construct(
        private SpaceContentColumnRepository $columnRepository,
        private SpaceContentItemRepository $itemRepository,
        private SpaceContentColumnSerializerInterface $columnSerializer,
        private SpaceContentItemSerializerInterface $itemSerializer,
        private SpaceContentCommentRepository $commentRepository,
        private SpaceContentAttachmentRepository $attachmentRepository,
        private SpaceContentCommentSerializerInterface $commentSerializer,
        private SpaceContentAttachmentSerializerInterface $attachmentSerializer,
        private CustomerSpaceSerializerInterface $spaceSerializer,
        private PathTemplateGenerator $pathTemplates,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /**
     * Everything the three views need, sent once.
     *
     * One payload and not one per view, because they are three readings of the
     * same rows: a calendar that fetched a shape of its own would be the first
     * place the two could disagree. Switching views is then instant and costs
     * no request, which is what makes it a preference rather than a
     * destination.
     *
     * @return array<string, mixed>
     */
    public function contentView(CustomerSpaceInterface $space): array
    {
        return [
            'space' => $this->spaceSerializer->serialize($space),
            'columns' => $this->columns($space),
            'items' => $this->items($space),
            'comments' => $this->comments($space),
            'attachments' => $this->attachments($space),
            'backPath' => $this->urlGenerator->generate('backend_studio_spaces'),
            'boardPath' => $this->urlGenerator->generate('workspace_space_content', ['id' => $space->getId()]),
            'accessPath' => $this->urlGenerator->generate('workspace_space_access', ['id' => $space->getId()]),
            // L'invitation à relire vit sur le tableau parce que c'est là que
            // le studio se trouve quand son lot est prêt, même si la route
            // appartient aux accès : ce qu'elle fait, c'est émettre des liens.
            'reviewPath' => $this->urlGenerator->generate('workspace_space_access_review', ['id' => $space->getId()]),
            'awaitingApproval' => $this->itemRepository->countAwaitingApproval($space),
            // Combien, et depuis combien de temps c'est dû : « trois en
            // attente » et « trois en attente dont deux en retard » ne
            // décrivent pas la même journée.
            'lateForReview' => $this->itemRepository->countLateForReview($space, new DateTimeImmutable()),
            'itemCreatePath' => $this->urlGenerator->generate('workspace_space_content_item_create', ['id' => $space->getId()]),
            'itemUpdatePath' => $this->pathTemplates->generate('workspace_space_content_item_update', ['id' => $space->getId(), 'itemId' => '__id__']),
            'itemDeletePath' => $this->pathTemplates->generate('workspace_space_content_item_delete', ['id' => $space->getId(), 'itemId' => '__id__']),
            'itemReorderPath' => $this->urlGenerator->generate('workspace_space_content_item_reorder', ['id' => $space->getId()]),
            'schedulePath' => $this->pathTemplates->generate('workspace_space_content_item_schedule', ['id' => $space->getId(), 'itemId' => '__id__']),
            'commentPostPath' => $this->pathTemplates->generate('workspace_space_content_comment_post', ['id' => $space->getId(), 'itemId' => '__id__']),
            'commentDeletePath' => $this->pathTemplates->generate('workspace_space_content_comment_delete', ['id' => $space->getId(), 'commentId' => '__id__']),
            'attachmentUploadPath' => $this->pathTemplates->generate('workspace_space_content_attachment_upload', ['id' => $space->getId(), 'itemId' => '__id__']),
            'attachmentAttachPath' => $this->pathTemplates->generate('workspace_space_content_attachment_attach', ['id' => $space->getId(), 'itemId' => '__id__']),
            'attachmentDetachPath' => $this->pathTemplates->generate('workspace_space_content_attachment_detach', ['id' => $space->getId(), 'attachmentId' => '__id__']),
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

    /**
     * The threads of the space, keyed by the card they hang off.
     *
     * The whole space at once rather than a fetch per card opened: both screens
     * draw every card and open one of them, and a space's threads are small -
     * this is a validation loop, not a forum.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function comments(CustomerSpaceInterface $space): array
    {
        $byItem = [];

        foreach ($this->commentRepository->findForSpaceByItem($space) as $itemId => $comments) {
            $byItem[$itemId] = array_map($this->commentSerializer->serialize(...), $comments);
        }

        return $byItem;
    }

    /**
     * The files of the space, keyed by the card they sit on.
     *
     * The whole space at once, for the same reason as the threads: the three
     * views draw every card, and a card shows its thumbnails without being
     * opened.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function attachments(CustomerSpaceInterface $space): array
    {
        $byItem = [];

        foreach ($this->attachmentRepository->findForSpaceByItem($space) as $itemId => $attachments) {
            $byItem[$itemId] = array_map($this->attachmentSerializer->serialize(...), $attachments);
        }

        return $byItem;
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
            'comments' => $this->comments($space),
            'attachments' => $this->attachments($space),
        ];
    }
}
