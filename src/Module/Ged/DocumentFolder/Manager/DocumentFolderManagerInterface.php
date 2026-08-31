<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\DocumentFolder\Manager;

use Aurora\Module\Ged\DocumentFolder\Dto\DocumentFolderInputInterface;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolderInterface;

interface DocumentFolderManagerInterface
{
    public function create(DocumentFolderInputInterface $input): DocumentFolderInterface;

    public function update(DocumentFolderInterface $folder, DocumentFolderInputInterface $input): void;

    public function delete(DocumentFolderInterface $folder): void;

    /**
     * Refiles a folder under a new parent, or at the root with null.
     *
     * @return bool false when the move would file the folder inside its own
     *              descendant - a cycle, which takes both branches off every
     *              screen that builds a tree from the flat list. Nothing is
     *              written in that case.
     */
    public function move(DocumentFolderInterface $folder, ?DocumentFolderInterface $newParent): bool;

    /** @param list<int> $orderedIds */
    public function reorder(array $orderedIds): void;
}
