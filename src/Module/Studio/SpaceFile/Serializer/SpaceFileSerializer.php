<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\Serializer;

use Aurora\Core\Storage\Enum\MimeGroupEnum;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceContent\Serializer\SpaceContentAttachmentSerializer;
use Aurora\Module\Studio\SpaceFile\Entity\SpaceFileInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use const DATE_ATOM;

/**
 * A space file, as both surfaces read it.
 *
 * The same shape as {@see SpaceContentAttachmentSerializer}, on purpose: the
 * Files view draws a single list from both, and two shapes would have forced
 * it to know which one it holds. What differs is the `itemId`, absent here -
 * that is precisely what this tab says.
 */
#[AsAlias(SpaceFileSerializerInterface::class)]
class SpaceFileSerializer implements SpaceFileSerializerInterface
{
    public function __construct(protected readonly UrlGeneratorInterface $urlGenerator) {}

    /** @return array<string, mixed> */
    public function serialize(SpaceFileInterface $file): array
    {
        $parameters = [
            'id' => $file->getSpace()->getId(),
            'fileId' => $file->getId(),
        ];

        return $this->shape($file) + [
            // For the studio only: the client's page only receives what is
            // shown to them, where the flag would only ever say "yes".
            'visibleToClient' => $file->isShownToClient(),
            // Through the space's route and not the media library's: a file
            // dropped here is a draft, which `DocumentUrlGenerator` addresses
            // through `suite_ged_files` behind a privilege someone who manages
            // customer spaces does not necessarily have.
            'url' => $this->urlGenerator->generate('workspace_space_files_file', $parameters + ['variant' => 'file']),
            'preview' => $this->isImage($file->getDocument())
                ? $this->urlGenerator->generate('workspace_space_files_file', $parameters + ['variant' => 'preview'])
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function serializeForGuest(SpaceFileInterface $file, SpaceAccessLinkInterface $link, string $token): array
    {
        $parameters = [
            'selector' => $link->getSelector(),
            'token' => $token,
            'fileId' => $file->getId(),
        ];

        // Without `documentId`: the document's id in the media library is
        // only useful to the studio, and has no business in a client's page.
        $shape = $this->shape($file);
        unset($shape['documentId']);

        return $shape + [
            'url' => $this->urlGenerator->generate('public_space_file_file', $parameters + ['variant' => 'file']),
            'preview' => $this->isImage($file->getDocument())
                ? $this->urlGenerator->generate('public_space_file_file', $parameters + ['variant' => 'preview'])
                : null,
        ];
    }

    /**
     * What does not depend on who is looking.
     *
     * @return array<string, mixed>
     */
    protected function shape(SpaceFileInterface $file): array
    {
        $document = $file->getDocument();

        return [
            'id' => $file->getId(),
            'documentId' => $document->getId(),
            'title' => $document->getTitle(),
            'originalName' => $document->getOriginalName(),
            'mimeType' => $document->getMimeType(),
            'size' => $document->getSize(),
            'author' => $file->getAuthorLabel(),
            'fromClient' => $file->isFromClient(),
            'createdAt' => $file->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    protected function isImage(DocumentInterface $document): bool
    {
        return MimeGroupEnum::Image->matches($document->getMimeType());
    }
}
