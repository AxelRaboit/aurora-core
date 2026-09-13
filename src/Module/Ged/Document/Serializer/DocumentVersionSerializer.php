<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Serializer;

use Aurora\Module\Ged\Document\Entity\DocumentVersionInterface;
use DateTimeInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A previous version is backend-only, always.
 *
 * Its file is a snapshot no living document points at, so the public
 * endpoint refuses it whatever the document's status is today - and rightly:
 * publishing the current file says nothing about the one it replaced, which
 * may be the draft somebody corrected. The address therefore goes straight to
 * the gated route, the only one that will ever answer for it.
 */
#[AsAlias(DocumentVersionSerializerInterface::class)]
class DocumentVersionSerializer implements DocumentVersionSerializerInterface
{
    public function __construct(
        protected readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function serialize(DocumentVersionInterface $version): array
    {
        return [
            'id' => $version->getId(),
            'versionNumber' => $version->getVersionNumber(),
            'fileName' => $version->getFileName(),
            'fileUrl' => $this->versionFileUrl($version),
            'fileMime' => $version->getMimeType(),
            'fileSize' => $version->getSize(),
            'note' => $version->getNote(),
            'createdAt' => $version->getCreatedAt()->format(DateTimeInterface::ATOM),
        ];
    }

    private function versionFileUrl(DocumentVersionInterface $version): ?string
    {
        $filePath = $version->getFilePath();

        if ('' === $filePath) {
            return null;
        }

        return $this->urlGenerator->generate('backend_ged_files', ['path' => $filePath]);
    }
}
