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
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Repository\NoteSpaceRepository;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function array_key_exists;
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
        protected readonly NoteSpaceAccess $spaceAccess,
        protected readonly NoteSpaceRepository $spaceRepository,
    ) {}

    public function create(CoreUserInterface $user, NoteFolderInputInterface $input): NoteFolderInterface
    {
        $folder = $this->createFolder();
        $folder->setUser($user);

        // Un parent impose son espace ; à la racine, c'est celui demandé, et
        // par défaut son espace personnel.
        $parent = null === $input->getParentId() ? null : $this->folderRepository->find($input->getParentId());
        $space = null === $input->getSpaceId() ? null : $this->spaceRepository->find($input->getSpaceId());
        $folder->setSpace(match (true) {
            $parent instanceof NoteFolderInterface => $parent->getSpace(),
            $space instanceof NoteSpaceInterface => $space,
            default => $this->spaceAccess->personalSpace($user),
        });

        $this->applyInput($folder, $input);

        if (null === $input->getPosition()) {
            $folder->setPosition($this->nextPosition($folder->getSpace(), $folder->getParent()?->getId()));
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

        $descendants = $this->folderRepository->findTrashedWith($folderId);
        $this->auditDeletedMany([...$descendants, $folder]);

        foreach ($descendants as $descendant) {
            $this->entityManager->remove($descendant);
        }

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

        $this->auditDeletedMany($folders);

        foreach ($folders as $folder) {
            $this->entityManager->remove($folder);
        }

        $this->entityManager->flush();

        return count($folders);
    }

    public function move(NoteFolderInterface $folder, ?NoteFolderInterface $newParent, ?NoteSpaceInterface $space = null): bool
    {
        if ($this->hierarchy->wouldCreateCycle($folder, $newParent)) {
            return false;
        }

        if ($this->hierarchy->wouldExceedDepth($newParent, $this->branchHeight($folder))) {
            return false;
        }

        $target = $newParent?->getSpace() ?? $space ?? $folder->getSpace();

        // Past everything already in the new parent, notes included: folders
        // and notes share one order among siblings.
        if ($folder->getParent()?->getId() !== $newParent?->getId() || $folder->getSpace()->getId() !== $target->getId()) {
            $folder->setPosition($this->nextPosition($target, $newParent?->getId()));
        }

        $this->changeSpace($folder, $target);
        $folder->setParent($newParent);
        $this->entityManager->flush();

        $this->auditUpdated($folder);

        return true;
    }

    /** The rank after the last folder or note of a parent (or of a space's root). */
    protected function nextPosition(NoteSpaceInterface $space, ?int $parentId): int
    {
        $max = max(
            $this->folderRepository->findMaxPositionForUserAndParent($space, $parentId) ?? -1,
            $this->noteRepository->findMaxPositionForUserAndFolder($space, $parentId) ?? -1,
        );

        return $max + 1;
    }

    /**
     * Fait passer un dossier dans un autre espace, avec tout ce qu'il range.
     *
     * Une note vit toujours dans l'espace de son dossier : laisser la branche
     * derrière ferait un dossier partagé plein de notes que personne d'autre
     * ne voit.
     */
    protected function changeSpace(NoteFolderInterface $folder, NoteSpaceInterface $space): void
    {
        if ($space->getId() === $folder->getSpace()->getId()) {
            return;
        }

        // Toute la branche, corbeille comprise : un dossier ou une note jeté
        // puis restauré doit retrouver son parent dans le même espace.
        $branch = [$folder];
        $level = [(int) $folder->getId()];
        $seen = [(int) $folder->getId() => true];

        while ([] !== $level) {
            $next = [];
            foreach ($this->folderRepository->findAllChildrenOfAny($level) as $child) {
                $id = (int) $child->getId();
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $branch[] = $child;
                    $next[] = $id;
                }
            }

            $level = $next;
        }

        foreach ($branch as $one) {
            $one->setSpace($space);
        }

        $ids = array_map(static fn (NoteFolderInterface $one): int => (int) $one->getId(), $branch);

        foreach ($this->noteRepository->findAllInFolders($ids) as $note) {
            $this->noteManager->changeSpace($note, $space);
        }
    }

    public function reorder(CoreUserInterface $user, array $entries): void
    {
        if ([] === $entries) {
            return;
        }

        // Every folder of the person, not only those named: a parent is
        // usually *not* among the siblings being reordered, and it used to
        // resolve to null - reordering the folders of a subfolder sent all of
        // them to the root. The stored parents also close the cycle check,
        // which only looked at the entries and let "A under its own child B"
        // through when B was not sent.
        $all = [];
        foreach ($this->folderRepository->findAllForUser($user) as $folder) {
            $all[(int) $folder->getId()] = $folder;
        }

        // Seulement ce que la personne peut écrire : un dossier d'équipe se
        // range par ceux qui en ont le droit.
        $byId = [];
        foreach ($entries as $entry) {
            $id = (int) $entry['id'];
            if (isset($all[$id]) && $this->spaceAccess->canWriteFolder($user, $all[$id])) {
                $byId[$id] = $all[$id];
            }
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

            // A parent that is not the person's folder is refused whole too:
            // silently falling back to the root would move it somewhere
            // nobody asked for.
            if (null !== $parentId && !isset($all[(int) $parentId])) {
                return;
            }

            // Changer d'espace n'est pas l'affaire d'un réordonnancement : la
            // branche entière doit suivre, et c'est move() qui le fait.
            if (null !== $parentId && $all[(int) $parentId]->getSpace()->getId() !== $byId[$id]->getSpace()->getId()) {
                return;
            }

            $parentMap[$id] = null === $parentId ? null : (int) $parentId;
        }

        $storedParent = static fn (int $id): ?int => ($all[$id] ?? null)?->getParent()?->getId();

        foreach ($parentMap as $id => $firstParentId) {
            $visited = [$id => true];
            for ($current = $firstParentId; null !== $current; $current = array_key_exists($current, $parentMap) ? $parentMap[$current] : $storedParent($current)) {
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
            $folder->setParent(null !== $parentId ? $all[$parentId] : null);
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
        // A level per query: asking each node for its children cost one query
        // per folder of the branch, leaves included.
        $found = [];
        $level = [(int) $folder->getId()];
        $seen = [(int) $folder->getId() => true];

        while ([] !== $level) {
            $next = [];
            foreach ($this->folderRepository->findLivingChildrenOfAny($level) as $child) {
                $id = (int) $child->getId();
                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $found[] = $child;
                $next[] = $id;
            }

            $level = $next;
        }

        return $found;
    }

    /** How many levels the branch starting at this folder occupies. */
    protected function branchHeight(NoteFolderInterface $folder): int
    {
        $height = 1;
        $level = [(int) $folder->getId()];

        // A level per query, as in descendantsOf().
        while ([] !== $level) {
            $next = array_map(
                static fn (NoteFolderInterface $child): int => (int) $child->getId(),
                $this->folderRepository->findLivingChildrenOfAny($level),
            );

            if ([] !== $next) {
                ++$height;
            }

            $level = $next;
        }

        return $height;
    }

    /**
     * Le parent où un dossier peut être rangé : du même espace. Null sinon.
     */
    protected function parentFor(NoteFolderInterface $folder, ?int $parentId): ?NoteFolderInterface
    {
        if (null === $parentId) {
            return null;
        }

        $parent = $this->folderRepository->find($parentId);

        if (!$parent instanceof NoteFolderInterface || $parent->getSpace()->getId() !== $folder->getSpace()->getId()) {
            return null;
        }

        return $parent;
    }

    protected function createFolder(): NoteFolderInterface
    {
        return new NoteFolder();
    }

    protected function applyInput(NoteFolderInterface $folder, NoteFolderInputInterface $input): void
    {
        $folder->setName($input->getName());
        $folder->setColor($input->getColor());

        $parent = $this->parentFor($folder, $input->getParentId());

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

    /**
     * The same lines as `auditDeleted()`, written together before any row
     * goes: audited one by one inside the loop, each line's flush also
     * executed the removal queued before it, one row at a time.
     *
     * @param list<NoteFolderInterface> $folders
     */
    protected function auditDeletedMany(array $folders): void
    {
        $this->auditLogger->logMany('notes_markdown', 'folder.deleted', 'NoteFolder', array_map(
            fn (NoteFolderInterface $folder): array => ['id' => $folder->getId(), 'data' => $this->auditPayload($folder)],
            $folders,
        ));
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
            // La couleur, elle, peut y figurer : elle ne dit rien de ce que
            // le dossier contient.
            'color' => $folder->getColor(),
        ];
    }
}
