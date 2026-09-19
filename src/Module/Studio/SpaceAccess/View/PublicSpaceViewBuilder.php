<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\View;

use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentAttachmentRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentCommentRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use Aurora\Module\Studio\SpaceContent\Serializer\SpaceContentAttachmentSerializerInterface;
use Aurora\Module\Studio\SpaceContent\Serializer\SpaceContentColumnSerializerInterface;
use Aurora\Module\Studio\SpaceContent\Serializer\SpaceContentCommentSerializerInterface;
use Aurora\Module\Studio\SpaceContent\Serializer\SpaceContentItemSerializerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
        private PathTemplateGenerator $pathTemplates,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

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

        return [
            'space' => [
                'name' => $space->getName(),
                'description' => $space->getDescription(),
                'customerName' => $space->getCustomer()->getLegalName(),
                'colourSlot' => $space->getColourSlot(),
                'timezone' => $space->getTimezone(),
            ],
            'columns' => array_map(
                $this->columnSerializer->serialize(...),
                $this->columns->findForSpace($space),
            ),
            'items' => $this->items($link),
            'comments' => $this->comments($link),
            'attachments' => $this->attachments($link, $token),
            'expiresAt' => $link->getExpiresAt(),
            'canApprove' => $link->canApprove(),
            'canComment' => $link->canComment(),
            'canUpload' => $link->canUpload(),
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
            // Le dossier Drive, s'il y en a un. Les adresses sont posées même
            // quand le dossier est vide : l'écran décide de se montrer sur ce
            // que la liste rend, et non sur ce que le serveur suppose.
            'drivePath' => null === $link->getSpace()->getDriveFolderId()
                ? null
                : $this->urlGenerator->generate('public_space_drive', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                ]),
            'driveFilePath' => null === $link->getSpace()->getDriveFolderId()
                ? null
                : $this->pathTemplates->generate('public_space_drive_file', [
                    'selector' => $link->getSelector(),
                    'token' => $token,
                    'fileId' => '__id__',
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
        $byItem = [];

        foreach ($this->commentRepository->findForSpaceByItem($link->getSpace()) as $itemId => $comments) {
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
        $byItem = [];

        foreach ($this->attachmentRepository->findForSpaceByItem($link->getSpace()) as $itemId => $attachments) {
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
        return [
            'success' => true,
            'items' => $this->items($link),
            'comments' => $this->comments($link),
            'attachments' => $this->attachments($link, $token),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function items(SpaceAccessLinkInterface $link): array
    {
        return array_map(
            $this->itemSerializer->serialize(...),
            $this->items->findForSpace($link->getSpace()),
        );
    }
}
