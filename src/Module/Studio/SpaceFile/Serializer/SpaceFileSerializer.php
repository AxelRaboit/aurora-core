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
 * Un fichier de l'espace, tel que les deux surfaces le lisent.
 *
 * La même forme que {@see SpaceContentAttachmentSerializer}, volontairement :
 * la vue Fichiers dessine une seule liste à partir des deux, et deux formes
 * l'auraient obligée à savoir laquelle elle tient. Ce qui diffère est le
 * `itemId`, absent ici - c'est précisément ce que cet onglet dit.
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
            // Par la route de l'espace et pas celle de la médiathèque : un
            // fichier déposé ici est un brouillon, que `DocumentUrlGenerator`
            // adresse par `backend_ged_files` derrière un privilège que
            // quelqu'un qui gère des espaces clients n'a pas forcément.
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

        return $this->shape($file) + [
            'url' => $this->urlGenerator->generate('public_space_file_file', $parameters + ['variant' => 'file']),
            'preview' => $this->isImage($file->getDocument())
                ? $this->urlGenerator->generate('public_space_file_file', $parameters + ['variant' => 'preview'])
                : null,
        ];
    }

    /**
     * Ce qui ne dépend pas de qui regarde.
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
