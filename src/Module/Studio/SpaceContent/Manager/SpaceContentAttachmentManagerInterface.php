<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Manager;

use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentAttachmentInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

interface SpaceContentAttachmentManagerInterface
{
    /**
     * Puts a document that already exists in GED on a card.
     *
     * The picker's half of the story: a logo, a press kit, a photo filed last
     * month. Nothing is uploaded and nothing is copied.
     */
    public function attachAsStudio(SpaceContentItemInterface $item, DocumentInterface $document): SpaceContentAttachmentInterface;

    /**
     * Files an uploaded file in GED and puts it on a card, in one gesture.
     */
    public function uploadAsStudio(SpaceContentItemInterface $item, UploadedFile $file): SpaceContentAttachmentInterface;

    /**
     * The same, from a client holding a link.
     *
     * The link's right to upload is the caller's to check; what is checked here
     * is that the card belongs to the space that link opens.
     */
    public function uploadAsClient(
        SpaceContentItemInterface $item,
        SpaceAccessLinkInterface $link,
        UploadedFile $file,
    ): SpaceContentAttachmentInterface;

    /**
     * Takes a file off a card.
     *
     * The document stays in GED. Only the studio can reach this.
     */
    public function detach(SpaceContentAttachmentInterface $attachment): void;
}
