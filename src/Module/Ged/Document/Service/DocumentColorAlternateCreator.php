<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Service;

use Aurora\Core\Storage\Enum\MimeTypeEnum;
use Aurora\Module\Ged\Document\Dto\ColorAlternateInput;
use Aurora\Module\Ged\Document\Dto\DocumentInput;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Manager\DocumentManagerInterface;

use function mb_substr;
use function sprintf;

/**
 * Declines a visual in another colour and files the copy as its alternate.
 *
 * The yellow, red and blue copies of the green visuals used to be made by
 * scripts, outside the library, then imported and attached by hand. This is
 * the same result from the document itself: pick a colour and a label, and
 * the copy is generated, stored, and born in the family - with the original's
 * folder, category, tags, alternative text and credit, and kept on purpose,
 * since a new colour is rarely used the day it is made.
 *
 * Asked from an alternate, it declines the original: an alternate cannot
 * have alternates of its own, and a copy of a copy would drift.
 */
final readonly class DocumentColorAlternateCreator
{
    public function __construct(
        private GedDocumentUploader $uploader,
        private DocumentManagerInterface $documentManager,
        private DocumentFamilyRule $familyRule,
    ) {}

    /**
     * @return DocumentInterface|array<string, string> the new alternate, or
     *                                                 field => translation key
     */
    public function create(DocumentInterface $document, ColorAlternateInput $input): DocumentInterface|array
    {
        $original = $document->getOriginal() ?? $document;
        $mime = MimeTypeEnum::tryFrom((string) $original->getMimeType());

        if (!$mime?->isRasterImage() || $mime->supportsAnimation() || null === $original->getFilePath()) {
            return ['color' => 'backend.ged.documents.recolor.errors.not_an_image'];
        }

        $alternate = $this->input($original, $input, []);
        $errors = $this->familyRule->errors(null, $alternate);
        if ([] !== $errors) {
            return $errors;
        }

        $file = $this->uploader->recolorToNewFile(
            $original->getFilePath(),
            $original->getStorageDisk(),
            $mime->value,
            $input->color,
            $input->sourceColor,
            $input->spare,
            $input->protectDetail,
        );

        if (null === $file) {
            return ['color' => 'backend.ged.documents.recolor.errors.nothing_to_replace'];
        }

        return $this->documentManager->create($this->input($original, $input, $file));
    }

    /**
     * The alternate's input: the original's description of itself, the new
     * file, and its place in the family.
     *
     * @param array{filePath?: string, fileName?: string, mimeType?: string, size?: int, width?: int, height?: int} $file
     */
    private function input(DocumentInterface $original, ColorAlternateInput $input, array $file): DocumentInput
    {
        $tagIds = [];
        foreach ($original->getTags() as $tag) {
            $tagIds[] = (int) $tag->getId();
        }

        return new DocumentInput(
            title: mb_substr(sprintf('%s (%s)', $original->getTitle(), $input->label), 0, 200),
            description: $original->getDescription(),
            status: $original->getStatus(),
            categoryId: $original->getCategory()?->getId(),
            filePath: $file['filePath'] ?? null,
            fileName: $file['fileName'] ?? null,
            originalName: $original->getOriginalName(),
            mimeType: $file['mimeType'] ?? null,
            size: $file['size'] ?? null,
            width: $file['width'] ?? null,
            height: $file['height'] ?? null,
            alt: $original->getAlt(),
            caption: $original->getCaption(),
            tagIds: $tagIds,
            folderId: $original->getFolder()?->getId(),
            focalX: $original->getFocalX(),
            focalY: $original->getFocalY(),
            sourceUrl: $original->getSourceUrl(),
            attributionName: $original->getAttributionName(),
            attributionUrl: $original->getAttributionUrl(),
            kept: true,
            originalId: $original->getId(),
            alternateLabel: $input->label,
        );
    }
}
