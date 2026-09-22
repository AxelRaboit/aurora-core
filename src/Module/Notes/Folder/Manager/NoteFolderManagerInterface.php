<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Folder\Manager;

use Aurora\Module\Notes\Folder\Dto\NoteFolderInputInterface;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;

interface NoteFolderManagerInterface
{
    public function create(CoreUserInterface $user, NoteFolderInputInterface $input): NoteFolderInterface;

    public function update(NoteFolderInterface $folder, NoteFolderInputInterface $input): void;

    /**
     * Moves a folder to the trash, with everything inside it.
     *
     * Always with its contents, unlike the GED, where a document has a life
     * of its own at the root: here the folder is the filing and the notes are
     * what was filed, and a note surfacing alone at the root after its folder
     * was deleted is a note its author has lost track of.
     */
    public function delete(NoteFolderInterface $folder): void;

    /** Brings a folder back, with the folders and notes that fell with it. */
    public function restore(NoteFolderInterface $folder): void;

    /** Destroys a folder and everything that fell with it, images included. */
    public function forceDelete(NoteFolderInterface $folder): void;

    /** @return int how many folders were destroyed */
    public function purgeTrashedBefore(DateTimeImmutable $cutoff): int;

    /**
     * Refiles a folder under a new parent, or at the root with null.
     *
     * @return bool false when the move is refused - a cycle, or a branch that
     *              would sit deeper than a breadcrumb can show. Nothing is
     *              written in that case.
     */
    public function move(NoteFolderInterface $folder, ?NoteFolderInterface $newParent): bool;

    /**
     * Persists the order of a set of folders.
     *
     * @param list<array{id: int, parentId: ?int, position: int}> $entries
     */
    public function reorder(CoreUserInterface $user, array $entries): void;
}
