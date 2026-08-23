<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Serializer;

use Aurora\Core\Storage\Enum\MimeTypeEnum;
use Aurora\Core\Storage\Service\UploadUrlGenerator;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use DateTimeInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsAlias(DocumentSerializerInterface::class)]
class DocumentSerializer implements DocumentSerializerInterface
{
    public function __construct(
        protected readonly TranslatorInterface $translator,
        protected readonly UploadUrlGenerator $uploadUrlGenerator,
        protected readonly UrlGeneratorInterface $urlGenerator,
        protected readonly DocumentUrlGenerator $documentUrlGenerator,
    ) {}

    public function serialize(DocumentInterface $document): array
    {
        $category = $document->getCategory();
        $folder = $document->getFolder();

        return [
            'id' => $document->getId(),
            'reference' => $document->getReference(),
            'title' => $document->getTitle(),
            'description' => $document->getDescription(),
            'status' => $document->getStatus()->value,
            'statusLabel' => $this->translator->trans($document->getStatus()->getLabelKey()),
            'categoryId' => $category?->getId(),
            'categoryName' => $category?->getName(),
            // Self-owned file fields - no Media coupling. URL is built via
            // the canonical `uploads_serve` route through UploadUrlGenerator
            // (no hardcoded `/uploads/` prefix).
            'filePath' => $document->getFilePath(),
            'fileName' => $document->getFileName(),
            'originalName' => $document->getOriginalName(),
            'fileUrl' => $this->uploadUrlGenerator->publicUrl($document->getFilePath()),
            // Stable canonical URL that survives file renames/re-uploads -
            // /document/{id} redirects to the current file (cf. MediaViewController).
            'permalink' => null === $document->getId()
                ? null
                : $this->urlGenerator->generate('ged_document_view', ['id' => $document->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            'fileMime' => $document->getMimeType(),
            'fileSize' => $document->getSize(),
            'width' => $document->getWidth(),
            'height' => $document->getHeight(),
            'alt' => $document->getAlt(),
            'caption' => $document->getCaption(),
            // Server-side rendered thumbnail for opaque formats (PDFs).
            // For native image MIMEs, fall back to the source file itself
            // so the list UI can always show *something*.
            'thumbnailUrl' => $this->resolveThumbnailUrl($document),
            'tagIds' => $document->getTags()->map(static fn ($tag): ?int => $tag->getId())->toArray(),
            'tags' => $document->getTags()->map(static fn ($tag): array => ['id' => $tag->getId(), 'name' => $tag->getName(), 'color' => $tag->getColor()])->toArray(),
            'folderId' => $folder?->getId(),
            'folderName' => $folder?->getName(),
            // Focal point + responsive variant URLs (raster images only).
            // Variants is a map { thumbnail|medium|large => /uploads/... }
            // so consumers can build a srcset without re-knowing the size
            // labels. `focalPositionCss` is precomputed so the front can
            // drop it straight into `style="object-position: ..."`.
            'focalX' => $document->getFocalX(),
            'focalY' => $document->getFocalY(),
            'focalPositionCss' => $this->documentUrlGenerator->focalPositionCss($document),
            'variants' => $this->buildVariantUrls($document),
            'createdAt' => $document->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updatedAt' => $document->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, string> */
    private function buildVariantUrls(DocumentInterface $document): array
    {
        $urls = [];
        foreach (array_keys($document->getVariants()) as $variantName) {
            $url = $this->documentUrlGenerator->variantUrl($document, $variantName);
            if (null !== $url) {
                $urls[$variantName] = $url;
            }
        }

        return $urls;
    }

    private function resolveThumbnailUrl(DocumentInterface $document): ?string
    {
        if (null !== $document->getThumbnailPath()) {
            return $this->uploadUrlGenerator->publicUrl($document->getThumbnailPath());
        }

        $mime = MimeTypeEnum::tryFrom($document->getMimeType() ?? '');
        if (null !== $mime && str_starts_with($mime->value, 'image/')) {
            return $this->uploadUrlGenerator->publicUrl($document->getFilePath());
        }

        return null;
    }
}
