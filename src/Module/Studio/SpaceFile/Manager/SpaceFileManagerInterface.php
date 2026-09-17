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

    public function uploadAsClient(
        CustomerSpaceInterface $space,
        SpaceAccessLinkInterface $link,
        UploadedFile $file,
    ): SpaceFileInterface;

    public function remove(SpaceFileInterface $file): void;
}
