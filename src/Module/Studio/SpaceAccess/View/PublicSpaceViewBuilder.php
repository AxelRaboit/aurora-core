<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\View;

use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Module\Studio\Customer\Serializer\CustomerInformationSerializerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumnInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentAttachmentRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentCommentRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use Aurora\Module\Studio\SpaceContent\Serializer\SpaceContentAttachmentSerializerInterface;
use Aurora\Module\Studio\SpaceContent\Serializer\SpaceContentColumnSerializerInterface;
use Aurora\Module\Studio\SpaceContent\Serializer\SpaceContentCommentSerializerInterface;
use Aurora\Module\Studio\SpaceContent\Serializer\SpaceContentItemSerializerInterface;
use Aurora\Module\Studio\SpaceResource\Repository\SpaceResourceRepository;
use Aurora\Module\Studio\SpaceResource\Serializer\SpaceResourceSerializerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use const DATE_ATOM;

/**
 * What a client is shown, which is less than what the studio sees.
 *
 * Built here rather than by reusing the board's builder, and the difference is
 * the point: that one hands out the addresses every write posts to, and a page
 * with no writes has no business carrying them. A read-only screen that
 * receives the URLs of six endpoints is one template mistake away from calling
 * one.
 *
 * The steps travel because a card says which one it is on, and the client
 * reading "à valider" beside a post is most of why they opened the page.
 */
final readonly class PublicSpaceViewBuilder
{
    public function __construct(
        private SpaceContentItemRepository $items,
        private SpaceContentColumnRepository $columns,
        private SpaceContentItemSerializerInterface $itemSerializer,
        private SpaceContentColumnSerializerInterface $columnSerializer,
        private SpaceContentCommentRepository $commentRepository,
        private SpaceContentCommentSerializerInterface $commentSerializer,
        private SpaceContentAttachmentRepository $attachmentRepository,
        private SpaceContentAttachmentSerializerInterface $attachmentSerializer,
        private CustomerInformationSerializerInterface $informationSerializer,
        private SpaceResourceRepository $resources,
        private SpaceResourceSerializerInterface $resourceSerializer,
        private PathTemplateGenerator $pathTemplates,
        private UrlGeneratorInterface $urlGenerator,
        private DeliverableRepository $deliverables,
        private DocumentUrlGenerator $documentUrls,
    ) {}

    /**
     * The deliverables the studio opened to the client.
     *
     * Closed ones do not leave the server: the query leaves them out, not the
     * page.
     *
     * Each deliverable's image comes with it, if it is published in the media
     * library: the client is not logged in, and a private image would not
     * display for them. It is then simply not sent.
     *
     * @return list<array{id: int, title: string, description: ?string, format: string, updatedAt: string, url: string, thumbnailUrl: ?string, thumbnailPosition: ?string}>
     */
    private function documents(SpaceAccessLinkInterface $link, string $token): array
    {
        $documents = [];

        foreach ($this->deliverables->findForSpace($link->getSpace(), visibleOnly: true) as $deliverable) {
            $thumbnail = $deliverable->getThumbnail();
            $public = $thumbnail instanceof DocumentInterface && DocumentStatusEnum::Published === $thumbnail->getStatus();
            $documents[] = [
                'id' => (int) $deliverable->getId(),
                'title' => $deliverable->getTitle(),
                'description' => $deliverable->getSummary(),
                // A page or a presentation: the card says so before it opens.
                'format' => $deliverable->getFormat()->value,
                'updatedAt' => $deliverable->getUpdatedAt()->format(DATE_ATOM),
                'url' => $this->urlGenerator->generate('public_space_deliverable', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                    'deliverableId' => $deliverable->getId(),
                ]),
                'thumbnailUrl' => $public ? $this->documentUrls->thumbUrl($thumbnail) : null,
                'thumbnailPosition' => $public ? $this->documentUrls->focalPositionCss($thumbnail) : null,
            ];
        }

        return $documents;
    }

    /**
     * @param string $token the secret half, which only the request that carried
     *                      it can supply - it is not stored and cannot be read
     *                      back off the link
     *
     * @return array<string, mixed>
     */
    public function view(SpaceAccessLinkInterface $link, string $token): array
    {
        $space = $link->getSpace();
        $cards = $this->visibleCards($space);

        return [
            'space' => [
                'name' => $space->getName(),
                'description' => $space->getDescription(),
                'customerName' => $space->getCustomer()->getLegalName(),
                'colourSlot' => $space->getColourSlot(),
                'timezone' => $space->getTimezone(),
            ],
            // The steps the client sees, and only those. The studio's board
            // keeps its own; "Relecture juridique" does not need to be news to
            // them.
            // Without `visibleToClient`: every step that gets here is visible,
            // the field could only say "yes". A flag with a single value
            // informs nobody and suggests it has two.
            'columns' => array_map(
                function (SpaceContentColumnInterface $column): array {
                    $shape = $this->columnSerializer->serialize($column);
                    unset($shape['visibleToClient']);

                    return $shape;
                },
                $this->visibleColumns($space),
            ),
            'items' => $this->serializeCards($cards),
            'comments' => $this->commentsOn($space, $cards),
            'attachments' => $this->attachmentsOn($link, $token, $cards),
            // The client's record, when it says something.
            //
            // **`null` rather than an empty record**, because that is what the
            // tab reads to know whether it should exist: a company knows its
            // own name, and a tab that would teach it only that is a tab
            // opened once.
            'information' => $this->informationSerializer->hasContent($space->getCustomer())
                ? $this->informationSerializer->serialize($space->getCustomer())
                : null,
            // The resources opened to the client, and only those. The filter
            // is in the query: a closed resource does not leave the server,
            // because hiding it in the page would have made it a display
            // preference and not a decision.
            'resources' => array_map(
                $this->resourceSerializer->serializeForGuest(...),
                $this->resources->findForSpace($space, visibleOnly: true),
            ),
            // The documents written for this client and published: a draft
            // stays with the team until it is published. Each one opens through
            // the space's own link, with no extra password.
            'documents' => $this->documents($link, $token),
            'expiresAt' => $link->getExpiresAt(),
            'canApprove' => $link->canApprove(),
            'canComment' => $link->canComment(),
            'canUpload' => $link->canUpload(),
            // The page says so at the top: what is being looked at is not what
            // the client received, and nothing clicked there is sent.
            'preview' => $link->isPreview(),
            // The one address this page may post to, and only when it may.
            // A reader who cannot answer is handed no endpoint at all rather
            // than a button that would be refused.
            'answerPath' => $link->canApprove()
                ? $this->pathTemplates->generate('public_space_answer', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                    'itemId' => '__id__',
                ])
                : null,
            // Batch approval, never the change request: ten approvals say one
            // thing ten times, ten change requests without a word teach the
            // studio nothing.
            'approveManyPath' => $link->canApprove()
                ? $this->urlGenerator->generate('public_space_approve_many', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                ])
                : null,
            'commentPath' => $link->canComment()
                ? $this->pathTemplates->generate('public_space_comment', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                    'itemId' => '__id__',
                ])
                : null,
            'uploadPath' => $link->canUpload()
                ? $this->pathTemplates->generate('public_space_attachment', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                    'itemId' => '__id__',
                ])
                : null,
            // Sending a file to the space itself, from the Files tab. Handed to
            // a preview too, so the studio sees the page the client gets; the
            // page disables the button there and the route refuses a preview.
            'spaceFileUploadPath' => $link->canUpload()
                ? $this->urlGenerator->generate('public_space_file_upload', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                ])
                : null,
            // The Drive folder, if there is one. The addresses are set even
            // when the folder is empty: the screen decides to show itself from
            // what the list returns, not from what the server assumes.
            'drivePath' => !$link->canSeeDrive() || null === $link->getSpace()->getDriveFolderId()
                ? null
                : $this->urlGenerator->generate('public_space_drive', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                ]),
            'driveFilePath' => !$link->canSeeDrive() || null === $link->getSpace()->getDriveFolderId()
                ? null
                : $this->pathTemplates->generate('public_space_drive_file', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                    'fileId' => '__id__',
                ]),
            'driveArchivePath' => !$link->canSeeDrive() || null === $link->getSpace()->getDriveFolderId()
                ? null
                : $this->urlGenerator->generate('public_space_drive_archive', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                ]),
        ];
    }

    /**
     * The threads of the space, keyed by the card they hang off.
     *
     * The same shape the studio's screens read, because it is the same
     * conversation: a message that rendered differently depending on who asked
     * is how two people end up arguing about what was said.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function comments(SpaceAccessLinkInterface $link): array
    {
        return $this->commentsOn($link->getSpace(), $this->visibleCards($link->getSpace()));
    }

    /**
     * @param array<int, SpaceContentItemInterface> $cards
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function commentsOn(CustomerSpaceInterface $space, array $cards): array
    {
        $byItem = [];

        foreach ($this->commentRepository->findForSpaceByItem($space) as $itemId => $comments) {
            if (!isset($cards[(int) $itemId])) {
                continue;
            }

            $byItem[$itemId] = array_map($this->commentSerializer->serialize(...), $comments);
        }

        return $byItem;
    }

    /**
     * The files of the space, keyed by the card they sit on.
     *
     * The same shape the studio reads, and shown to a reader who may not
     * upload: seeing the visual is the point of being asked to approve, and it
     * has nothing to do with being allowed to add one.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function attachments(SpaceAccessLinkInterface $link, string $token): array
    {
        return $this->attachmentsOn($link, $token, $this->visibleCards($link->getSpace()));
    }

    /**
     * @param array<int, SpaceContentItemInterface> $cards
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function attachmentsOn(SpaceAccessLinkInterface $link, string $token, array $cards): array
    {
        $byItem = [];

        foreach ($this->attachmentRepository->findForSpaceByItem($link->getSpace()) as $itemId => $attachments) {
            if (!isset($cards[(int) $itemId])) {
                continue;
            }

            $byItem[$itemId] = array_map(
                // Addresses that go through the link rather than through GED's
                // public catch-all, so that revoking an access revokes the
                // pictures with it.
                fn ($attachment): array => $this->attachmentSerializer->serializeForGuest($attachment, $link, $token),
                $attachments,
            );
        }

        return $byItem;
    }

    /**
     * What a guest write answers with: the cards and the threads.
     *
     * Both, because a verdict carrying a message changes one of each, and a
     * page that patched its own copy would be the first place the two could
     * disagree.
     *
     * @return array<string, mixed>
     */
    public function threadPayload(SpaceAccessLinkInterface $link, string $token): array
    {
        $cards = $this->visibleCards($link->getSpace());

        return [
            'success' => true,
            'items' => $this->serializeCards($cards),
            'comments' => $this->commentsOn($link->getSpace(), $cards),
            'attachments' => $this->attachmentsOn($link, $token, $cards),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function items(SpaceAccessLinkInterface $link): array
    {
        return $this->serializeCards($this->visibleCards($link->getSpace()));
    }

    /**
     * @param array<int, SpaceContentItemInterface> $cards
     *
     * @return list<array<string, mixed>>
     */
    private function serializeCards(array $cards): array
    {
        return array_values(array_map($this->itemSerializer->serialize(...), $cards));
    }

    /**
     * The cards a client is allowed to see, indexed by id.
     *
     * **The same sieve for the three lists, and that is the whole point.**
     * Cards went through it, threads and attachments did not: the thread of a
     * step marked internal and its files went into the client's page, with
     * their download addresses. The screen showed none of it because it did
     * not know the card, which is the worst kind of leak: invisible in use,
     * complete in the source.
     *
     * **Both filters, and not only the column one.** Removing a step without
     * removing its cards would leave the cards of a hidden column in the
     * client's calendar, which reads them by date and not by step.
     *
     * Read once per page and passed to the three lists. Each one used to read
     * it again on its own, and the whole page read the board four times, on
     * every load and after every answer from the client.
     *
     * @return array<int, SpaceContentItemInterface> in board order
     */
    private function visibleCards(CustomerSpaceInterface $space): array
    {
        $cards = [];

        foreach ($this->items->findForSpace($space) as $item) {
            if ($item->isShownToClient()) {
                $cards[(int) $item->getId()] = $item;
            }
        }

        return $cards;
    }

    /**
     * The columns opened to the client.
     *
     * Filtered here rather than by a dedicated query: a space's board has a
     * handful of them, and the repository already serves the same rows to the
     * studio.
     *
     * @return list<SpaceContentColumnInterface>
     */
    private function visibleColumns(CustomerSpaceInterface $space): array
    {
        return array_values(array_filter(
            $this->columns->findForSpace($space),
            static fn (SpaceContentColumnInterface $column): bool => $column->isVisibleToClient(),
        ));
    }
}
