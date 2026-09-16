<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Serializer;

use Aurora\Core\Storage\Enum\MimeGroupEnum;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use Aurora\Module\Studio\Deck\Service\DeckPicture;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentAttachmentInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use const DATE_ATOM;

#[AsAlias(SpaceContentAttachmentSerializerInterface::class)]
class SpaceContentAttachmentSerializer implements SpaceContentAttachmentSerializerInterface
{
    public function __construct(
        protected readonly DocumentUrlGenerator $documentUrls,
        protected readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    /**
     * The same shape on both sides, like the thread.
     *
     * **Every address is resolved here and none is stored**, which is the rule
     * {@see DeckPicture} states for slides
     * and which holds for the same reason: the address of a file changes when
     * the file behind it is replaced, and a copy kept on the attachment row
     * would be a second truth that goes stale silently.
     *
     * **A preview only for what previews.** An image gets a thumbnail; a PDF, a
     * spreadsheet or a video gets `preview: null` and the card draws an icon
     * from the mime type instead. Sending a broken image address for a PDF is
     * how a file that uploaded correctly looks like a failure.
     *
     * @return array<string, mixed>
     */
    public function serialize(SpaceContentAttachmentInterface $attachment): array
    {
        $document = $attachment->getDocument();

        $parameters = [
            'id' => $attachment->getItem()->getSpace()->getId(),
            'attachmentId' => $attachment->getId(),
        ];

        return $this->shape($attachment) + [
            // Through the space's own route, not GED's. A file uploaded here
            // is a draft, which GED addresses through `backend_ged_files` and
            // gates on `ged.documents.view` - a privilege somebody who manages
            // client spaces need not hold, and without which the board would
            // draw broken images and say nothing. Whatever grants the board
            // grants what is on it, on both surfaces.
            'url' => $this->urlGenerator->generate('workspace_space_content_attachment_file', $parameters + ['variant' => 'file']),
            'preview' => $this->isImage($document)
                ? $this->urlGenerator->generate('workspace_space_content_attachment_file', $parameters + ['variant' => 'preview'])
                : null,
        ];
    }

    public function serializeForGuest(
        SpaceContentAttachmentInterface $attachment,
        SpaceAccessLinkInterface $link,
        string $token,
    ): array {
        $parameters = [
            'selector' => $link->getSelector(),
            'token' => $token,
            'attachmentId' => $attachment->getId(),
        ];

        return $this->shape($attachment) + [
            'url' => $this->urlGenerator->generate('public_space_attachment_file', $parameters + ['variant' => 'file']),
            'preview' => $this->isImage($attachment->getDocument())
                ? $this->urlGenerator->generate('public_space_attachment_file', $parameters + ['variant' => 'preview'])
                : null,
        ];
    }

    /**
     * Everything that does not depend on who is reading.
     *
     * @return array<string, mixed>
     */
    protected function shape(SpaceContentAttachmentInterface $attachment): array
    {
        $document = $attachment->getDocument();

        return [
            'id' => $attachment->getId(),
            'position' => $attachment->getPosition(),
            'documentId' => $document->getId(),
            'title' => $document->getTitle(),
            'originalName' => $document->getOriginalName(),
            'mimeType' => $document->getMimeType(),
            'size' => $document->getSize(),
            // The durable name, not the relation: an account can be deleted and
            // a link revoked, and "who sent this photo" is asked long after.
            'author' => $attachment->getAuthorLabel(),
            'fromClient' => $attachment->isFromClient(),
            'createdAt' => $attachment->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    protected function isImage(DocumentInterface $document): bool
    {
        return MimeGroupEnum::Image->matches($document->getMimeType());
    }
}
