<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Service;

use Aurora\Module\Ged\Document\Dto\DocumentInputFactoryInterface;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Manager\DocumentManagerInterface;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentCategory\Repository\DocumentCategoryRepository;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Module\Ged\GedBootstrapProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Files an image that arrived from an editing form rather than from GED's own
 * create screen.
 *
 * Two decisions live here rather than in the request, and that is the point:
 * the category and the status are not the client's to choose. A payload could
 * otherwise drop a banner picture into the contracts category, or leave it a
 * draft.
 *
 * **Published, not draft.** The picker lists published documents only. An
 * inline upload left as a draft would show up in the field that created it,
 * render on the public page — and be unfindable in "choose an image" ever
 * after. An asset nobody can reach again is the disorder this is meant to
 * avoid, not a safe default.
 *
 * **The dedicated category**, seeded by {@see GedBootstrapProvider}. A
 * project's categories describe its filing; a banner image belongs to none of
 * them, and an uncategorised document is exactly the litter this exists to
 * prevent.
 */
final readonly class InlineImageUploader
{
    public function __construct(
        private GedDocumentUploader $uploader,
        private DocumentManagerInterface $documentManager,
        private DocumentInputFactoryInterface $inputFactory,
        private DocumentCategoryRepository $documentCategoryRepository,
    ) {}

    public function upload(UploadedFile $file): DocumentInterface
    {
        $uploaded = $this->uploader->upload($file);

        return $this->documentManager->create($this->inputFactory->fromArray([
            // The filename is a poor title, and the only one available: asking
            // for a real one would put a form in front of the upload, which is
            // the detour the inline picker exists to remove. It stays editable
            // in GED like any other document.
            'title' => $uploaded['originalName'],
            'status' => DocumentStatusEnum::Published->value,
            'categoryId' => $this->categoryId(),
            'filePath' => $uploaded['filePath'],
            'fileName' => $uploaded['fileName'],
            'originalName' => $uploaded['originalName'],
            'mimeType' => $uploaded['mimeType'],
            'size' => $uploaded['size'],
            'width' => $uploaded['width'],
            'height' => $uploaded['height'],
            'thumbnailPath' => $uploaded['thumbnailPath'],
        ]));
    }

    /**
     * Null when the category is missing, which only happens on an install that
     * never ran the bootstrap. Uncategorised is worse than categorised and
     * better than a failed upload: the author keeps their image, and the row
     * is one query away from being filed.
     */
    private function categoryId(): ?int
    {
        $category = $this->documentCategoryRepository->findOneBy([
            'slug' => GedBootstrapProvider::INLINE_UPLOAD_CATEGORY,
        ]);

        return $category instanceof DocumentCategoryInterface ? (int) $category->getId() : null;
    }
}
