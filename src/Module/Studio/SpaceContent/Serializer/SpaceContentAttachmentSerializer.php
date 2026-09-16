<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Serializer;

use Aurora\Core\Storage\Enum\MimeGroupEnum;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use Aurora\Module\Studio\Deck\Service\DeckPicture;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentAttachmentInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use const DATE_ATOM;

#[AsAlias(SpaceContentAttachmentSerializerInterface::class)]
class SpaceContentAttachmentSerializer implements SpaceContentAttachmentSerializerInterface
{
    public function __construct(protected readonly DocumentUrlGenerator $documentUrls) {}

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
        $isImage = MimeGroupEnum::Image->matches($document->getMimeType());

        return [
            'id' => $attachment->getId(),
            'position' => $attachment->getPosition(),
            'documentId' => $document->getId(),
            'title' => $document->getTitle(),
            'originalName' => $document->getOriginalName(),
            'mimeType' => $document->getMimeType(),
            'size' => $document->getSize(),
            'url' => $this->documentUrls->publicUrl($document),
            'preview' => $isImage ? $this->documentUrls->thumbUrl($document) : null,
            // The durable name, not the relation: an account can be deleted and
            // a link revoked, and "who sent this photo" is asked long after.
            'author' => $attachment->getAuthorLabel(),
            'fromClient' => $attachment->isFromClient(),
            'createdAt' => $attachment->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
