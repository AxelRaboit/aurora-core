<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\Manager;

use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceFile\Entity\SpaceFileInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

interface SpaceFileManagerInterface
{
    public function attachAsStudio(CustomerSpaceInterface $space, DocumentInterface $document): SpaceFileInterface;

    public function uploadAsStudio(CustomerSpaceInterface $space, UploadedFile $file): SpaceFileInterface;

    /**
     * Files what a client sent through their link, signed as theirs and shown
     * to them, and tells the space's team.
     *
     * The link's right and its kind (a preview is never a sender) are the
     * caller's to check, as on a card.
     */
    public function uploadAsClient(SpaceAccessLinkInterface $link, UploadedFile $file): SpaceFileInterface;

    public function remove(SpaceFileInterface $file): void;

    /** Shows the file to the client or hides it; a file the client sent stays shown. */
    public function setVisibleToClient(SpaceFileInterface $file, bool $visible): void;
}
