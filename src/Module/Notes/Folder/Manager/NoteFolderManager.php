<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Folder\Manager;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Notes\Folder\Dto\NoteFolderInputInterface;
use Aurora\Module\Notes\Folder\Entity\NoteFolder;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Repository\NoteFolderRepository;
use Aurora\Module\Notes\Folder\Service\NoteFolderHierarchy;
use Aurora\Module\Notes\Markdown\Manager\MarkdownNoteManagerInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function count;

#[AsAlias(NoteFolderManagerInterface::class)]
class NoteFolderManager implements NoteFolderManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly NoteFolderRepository $folderRepository,
        protected readonly MarkdownNoteRepository $noteRepository,
        protected readonly MarkdownNoteManagerInterface $noteManager,
        protected readonly NoteFolderHierarchy $hierarchy,
        protected readonly AuditLogger $auditLogger,
    ) {}

    public function create(CoreUserInterface $user, NoteFolderInputInterface $input): NoteFolderInterface
    {
        $folder = $this->createFolder();
        $folder->setUser($user);

        $this->applyInput($folder, $input);

        if (null === $input->getPosition()) {
            $maxPosition = $this->folderRepository->findMaxPositionForUserAndParent($user, $input->getParentId());
            $folder->setPosition(null === $maxPosition ? 0 : $maxPosition + 1);
        }

        $this->entityManager->persist($folder);
        $this->entityManager->flush();

        $this->auditCreated($folder);

        return $folder;
    }

    public function update(NoteFolderInterface $folder, NoteFolderInputInterface $input): void
    {
        $this->applyInput($folder, $input);
        $this->entityManager->flush();

        $this->auditUpdated($folder);
    }

    public function delete(NoteFolderInterface $folder): void
    {
        if ($folder->isTrashed()) {
            return;
        }

        $now = new DateTimeImmutable();
        $folderId = (int) $folder->getId();

        $folder->setDeletedAt($now)->setTrashedWithFolderId(null);

        $branchIds = [$folderId];

        foreach ($this->descendantsOf($folder) as $descendant) {
            $branchIds[] = (int) $descendant->getId();

            if ($descendant->isTrashed()) {
                continue;
            }

            $descendant->setDeletedAt($now)->setTrashedWithFolderId($folderId);
        }

        foreach ($this->noteRepository->findLivingInFolders($branchIds) as $note) {
            $note->setDeletedAt($now)->setTrashedWithFolderId($folderId);
        }

        $this->entityManager->flush();

        $this->auditTrashed($folder);
    }

    public function restore(NoteFolderInterface $folder): void
    {
        if (!$folder->isTrashed()) {
            return;
        }

        $parent = $folder->getParent();
        if ($parent instanceof NoteFolderInterface && $parent->isTrashed()) {
            $folder->setParent(null);
        }

        $folderId = (int) $folder->getId();

        $folder->setDeletedAt(null)->setTrashedWithFolderId(null);

        foreach ($this->folderRepository->findTrashedWith($folderId) as $descendant) {
            $descendant->setDeletedAt(null)->setTrashedWithFolderId(null);
        }

        foreach ($this->noteRepository->findTrashedWithFolder($folderId) as $note) {
            $note->setDeletedAt(null)->setTrashedWithFolderId(null);
        }

        $this->entityManager->flush();

        $this->auditRestored($folder);
    }

    /**
     * Destroys a folder for good, with the branch that fell with it.
     *
     * The notes go through their own manager rather than being removed here,
     * because destroying a note also destroys the images it carried, and that
     * rule lives in one place.
     */
    public function forceDelete(NoteFolderInterface $folder): void
    {
        $folderId = (int) $folder->getId();

        foreach ($this->noteRepository->findTrashedWithFolder($folderId) as $note) {
            $this->noteManager->forceDelete($note);
        }

        foreach ($this->folderRepository->findTrashedWith($folderId) as $descendant) {
            $this->auditDeleted($descendant);
            $this->entityManager->remove($descendant);
        }

        $this->auditDeleted($folder);

        $this->entityManager->remove($folder);
        $this->entityManager->flush();
    }

    /**
     * Destroys the folders that have been in the trash long enough.
     *
     * Deliberately not `forceDelete` in a loop: that method is the answer to
     * somebody clicking "delete for good" on one folder, and calling it here
     * would destroy notes the note purge is already responsible for, on its
     * own schedule. The foreign key is `SET NULL`, so whichever of the two
     * runs first cannot strand the other.
     */
    public function purgeTrashedBefore(DateTimeImmutable $cutoff): int
    {
        $folders = $this->folderRepository->findTrashedBefore($cutoff);
        if ([] === $folders) {
            return 0;
        }

        foreach ($folders as $folder) {
            $this->auditDeleted($folder);
            $this->entityManager->remove($folder);
        }

        $this->entityManager->flush();

        return count($folders);
    }

    /** Épingle le dossier, ou le décroche. L'heure ordonne le panneau. */
    public function toggleFavorite(NoteFolderInterface $folder): bool
    {
        $pinned = !$folder->getFavoritedAt() instanceof DateTimeImmutable;

        $folder->setFavoritedAt($pinned ? new DateTimeImmutable() : null);

        $this->entityManager->flush();

        $this->auditUpdated($folder);

        return $pinned;
    }

    public function move(NoteFolderInterface $folder, ?NoteFolderInterface $newParent): bool
    {
        if ($this->hierarchy->wouldCreateCycle($folder, $newParent)) {
            return false;
        }

        if ($this->hierarchy->wouldExceedDepth($newParent, $this->branchHeight($folder))) {
            return false;
        }

        $folder->setParent($newParent);
        $this->entityManager->flush();

        $this->auditUpdated($folder);

        return true;
    }

    public function reorder(CoreUserInterface $user, array $entries): void
    {
        if ([] === $entries) {
            return;
        }

        $ids = array_map(static fn (array $entry): int => (int) $entry['id'], $entries);

        $byId = [];
        foreach ($this->folderRepository->findBy(['id' => $ids, 'user' => $user]) as $folder) {
            $byId[(int) $folder->getId()] = $folder;
        }

        // The intended shape is built before anything is written, so a cycle
        // is refused whole rather than leaving half a reorder behind.
        $parentMap = [];
        foreach ($entries as $entry) {
            $id = (int) $entry['id'];
            if (!isset($byId[$id])) {
                continue;
            }

            $parentId = $entry['parentId'] ?? null;
            $parentMap[$id] = null === $parentId ? null : (int) $parentId;
        }

        foreach ($parentMap as $id => $firstParentId) {
            $visited = [$id => true];
            for ($current = $firstParentId; null !== $current; $current = $parentMap[$current] ?? null) {
                if (isset($visited[$current])) {
                    return;
                }

                $visited[$current] = true;
            }
        }

        // Parents are detached first to side-step the transient cycles a
        // reshuffle goes through, the way the note reorder does.
        foreach (array_keys($parentMap) as $id) {
            $byId[$id]->setParent(null);
        }

        foreach ($entries as $entry) {
            $id = (int) $entry['id'];
            $folder = $byId[$id] ?? null;
            if (null === $folder) {
                continue;
            }

            $parentId = $parentMap[$id] ?? null;
            $folder->setParent(null !== $parentId ? ($byId[$parentId] ?? null) : null);
            $folder->setPosition((int) $entry['position']);
        }

        $this->entityManager->flush();
    }

    /**
     * Every folder below this one, at any depth.
     *
     * @return list<NoteFolderInterface>
     */
    protected function descendantsOf(NoteFolderInterface $folder): array
    {
        $found = [];
        $queue = [$folder];

        while ([] !== $queue) {
            $current = array_shift($queue);
            foreach ($this->folderRepository->findLivingChildrenOf((int) $current->getId()) as $child) {
                $found[] = $child;
                $queue[] = $child;
            }
        }

        return $found;
    }

    /** How many levels the branch starting at this folder occupies. */
    protected function branchHeight(NoteFolderInterface $folder): int
    {
        $height = 1;
        $level = [$folder];

        while ([] !== $level) {
            $next = [];
            foreach ($level as $node) {
                foreach ($this->folderRepository->findLivingChildrenOf((int) $node->getId()) as $child) {
                    $next[] = $child;
                }
            }

            if ([] !== $next) {
                ++$height;
            }

            $level = $next;
        }

        return $height;
    }

    protected function createFolder(): NoteFolderInterface
    {
        return new NoteFolder();
    }

    protected function applyInput(NoteFolderInterface $folder, NoteFolderInputInterface $input): void
    {
        $folder->setName($input->getName());

        $parentId = $input->getParentId();
        $parent = null === $parentId
            ? null
            : $this->folderRepository->findOneByUserAndId($folder->getUser(), $parentId);

        // A parent that would make a cycle is dropped rather than refused:
        // `applyInput` is also the client extension point, and a hook that
        // throws on a hostile payload is a hook nobody can override safely.
        if (!$this->hierarchy->wouldCreateCycle($folder, $parent)) {
            $folder->setParent($parent);
        }

        if (null !== $input->getPosition()) {
            $folder->setPosition($input->getPosition());
        }
    }

    protected function auditCreated(NoteFolderInterface $folder): void
    {
        $this->auditLogger->log('notes_markdown', 'folder.created', 'NoteFolder', $folder->getId(), $this->auditPayload($folder));
    }

    protected function auditUpdated(NoteFolderInterface $folder): void
    {
        $this->auditLogger->log('notes_markdown', 'folder.updated', 'NoteFolder', $folder->getId(), $this->auditPayload($folder));
    }

    protected function auditTrashed(NoteFolderInterface $folder): void
    {
        $this->auditLogger->log('notes_markdown', 'folder.trashed', 'NoteFolder', $folder->getId(), $this->auditPayload($folder));
    }

    protected function auditRestored(NoteFolderInterface $folder): void
    {
        $this->auditLogger->log('notes_markdown', 'folder.restored', 'NoteFolder', $folder->getId(), $this->auditPayload($folder));
    }

    protected function auditDeleted(NoteFolderInterface $folder): void
    {
        $this->auditLogger->log('notes_markdown', 'folder.deleted', 'NoteFolder', $folder->getId(), $this->auditPayload($folder));
    }

    /**
     * What the audit trail keeps of a folder.
     *
     * Not the name: it is encrypted in the column precisely because it says
     * something about its author, and writing it in clear into the audit log
     * would undo that one row at a time.
     *
     * @return array<string, mixed>
     */
    protected function auditPayload(NoteFolderInterface $folder): array
    {
        return [
            'parentId' => $folder->getParent()?->getId(),
            'position' => $folder->getPosition(),
        ];
    }
}
