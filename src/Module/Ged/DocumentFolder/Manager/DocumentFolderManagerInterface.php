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
     * `$withAlternates` also takes, with the cascade, the alternates of its
     * documents that are filed outside the branch.
     */
    public function delete(DocumentFolderInterface $folder, bool $cascade = true, bool $withAlternates = false): void;

    /**
     * How many living alternates of the documents in this branch are filed
     * outside it - what `delete()` would take along with `$withAlternates`.
     */
    public function countAlternatesFiledOutside(DocumentFolderInterface $folder): int;

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
