<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\DocumentFolder\Manager;

use Aurora\Module\Ged\DocumentFolder\Dto\DocumentFolderInputInterface;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolderInterface;
use DateTimeImmutable;

interface DocumentFolderManagerInterface
{
    public function create(DocumentFolderInputInterface $input): DocumentFolderInterface;

    public function update(DocumentFolderInterface $folder, DocumentFolderInputInterface $input): void;

    /**
     * Moves a folder to the trash.
     *
     * With `$cascade`, everything under it goes too and a restore puts the
     * branch back as it was. Without, the contents surface at the root.
     */
    public function delete(DocumentFolderInterface $folder, bool $cascade = true): void;

    /** Brings a folder back, with whatever fell alongside it. */
    public function restore(DocumentFolderInterface $folder): void;

    /** Deletes the folder for good, releasing its contents to the root. */
    public function forceDelete(DocumentFolderInterface $folder): void;

    /** @return int how many folders were destroyed */
    public function purgeTrashedBefore(DateTimeImmutable $cutoff): int;

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
