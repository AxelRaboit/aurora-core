<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Service;

use Aurora\Module\Ged\Document\Dto\DocumentInputFactoryInterface;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Manager\DocumentManagerInterface;
use Aurora\Module\Ged\Document\Service\GedDocumentUploader;
use Aurora\Module\Ged\Document\Service\InlineImageUploader;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Files a document that arrived on a piece of content rather than through
 * GED's own create screen.
 *
 * The same shape as {@see InlineImageUploader},
 * and for the same reason: **the category and the status are not the caller's
 * to choose.** That matters more here than it does for an editorial banner,
 * because one of the callers is a client holding a link rather than an account.
 * A payload that could name its category would let an outsider drop a file into
 * the contracts category; one that could leave it a draft would put a file on a
 * card that nobody can find again in GED.
 *
 * **Draft, not published, and that is the security decision.** A published
 * document is served by the public catch-all to anybody holding its address,
 * with no session - so a photo a client sent for their February carousel would
 * have stayed readable at a fixed URL after their link was revoked. Filed as a
 * draft it is addressed through `backend_ged_files` for staff and through the
 * space's own link route for the client, and revoking the link actually
 * revokes the files.
 *
 * The cost is real and worth naming: GED's picker lists published documents
 * only, so a file that arrived through a space is not offered as a banner
 * image elsewhere. That reads as the right answer rather than a compromise - a
 * customer's photo is not site furniture - and the studio can publish it in
 * GED deliberately if it should be. It is still listed in GED's own screens,
 * filed under the client-spaces category; only the picker skips it.
 *
 * Nothing here decides whether the caller is allowed to upload. The right lives
 * on the link and is checked before this is reached, which is the same division
 * the comment thread uses: this service files bytes, it does not do security.
 */
final readonly class SpaceAttachmentUploader
{
    public function __construct(
        private GedDocumentUploader $uploader,
        private DocumentManagerInterface $documentManager,
        private DocumentInputFactoryInterface $inputFactory,
        private SpaceAttachmentCategoryProvider $spaceAttachmentCategoryProvider,
    ) {}

    public function upload(UploadedFile $file): DocumentInterface
    {
        $uploaded = $this->uploader->upload($file);

        return $this->documentManager->create($this->inputFactory->fromArray([
            // The filename is a poor title and the only one available: asking
            // for a real one would put a form in front of a drag and drop,
            // which is the detour this exists to remove. It stays editable in
            // GED like any other document.
            'title' => $uploaded['originalName'],
            'status' => DocumentStatusEnum::Draft->value,
            'categoryId' => (int) $this->spaceAttachmentCategoryProvider->resolve()->getId(),
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
}
