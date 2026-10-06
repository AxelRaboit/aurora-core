<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Service;

use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Repository\NoteFolderRepository;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\Space\Entity\NoteSpace;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Entity\NoteSpaceMemberInterface;
use Aurora\Module\Notes\Space\Enum\NoteSpaceAccessEnum;
use Aurora\Module\Notes\Space\Enum\NoteSpaceRoleEnum;
use Aurora\Module\Notes\Space\Repository\NoteSpaceRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * A person's role in a space, and everything that follows from it.
 *
 * **A single place decides.** Every route that reads or writes a note asks
 * here; none loads anything "by owner" any more. The rule:
 *
 * - one's personal space, and the ones one owns: manager;
 * - a space open to members: the role of one's membership;
 * - a space open to the whole back office: the default role, or the one of
 *   one's membership if it is stronger;
 * - otherwise, nothing: the space does not exist for the person.
 *
 * No special pass for administrators on the **content**: they have the
 * module's rights (create, publish), not other people's private notebooks.
 * Somebody's notebook stays theirs.
 */
final readonly class NoteSpaceAccess
{
    /** Create a shared space - one's personal space requires nothing. */
    public const string CREATE = 'notes.spaces.create';

    /** Publish a space on the web, readable without logging in. */
    public const string PUBLISH = 'notes.spaces.publish';

    public function __construct(
        private AuthorizationCheckerInterface $authorization,
        private NoteSpaceRepository $spaces,
        private MarkdownNoteRepository $notes,
        private NoteFolderRepository $folders,
        private EntityManagerInterface $entityManager,
    ) {}

    public function roleIn(CoreUserInterface $user, NoteSpaceInterface $space): ?NoteSpaceRoleEnum
    {
        $needsMembership = !$space->isPersonal() && !$this->isOwner($user, $space) && NoteSpaceAccessEnum::Private !== $space->getAccess();

        return $this->roleWith($user, $space, $needsMembership ? $this->spaces->findMembership($space, $user) : null);
    }

    /**
     * A person's role in several spaces, in one query.
     *
     * For a list: `roleIn()` looks up the membership space by space, which
     * would make one query per row.
     *
     * @param list<NoteSpaceInterface> $spaces
     *
     * @return array<int, NoteSpaceRoleEnum> space id => role, without the spaces closed to the person
     */
    public function rolesFor(CoreUserInterface $user, array $spaces): array
    {
        $memberships = [];
        foreach ($this->spaces->findMembershipsOf($user, $spaces) as $membership) {
            $memberships[(int) $membership->getSpace()->getId()] = $membership;
        }

        $roles = [];
        foreach ($spaces as $space) {
            $role = $this->roleWith($user, $space, $memberships[(int) $space->getId()] ?? null);
            if ($role instanceof NoteSpaceRoleEnum) {
                $roles[(int) $space->getId()] = $role;
            }
        }

        return $roles;
    }

    /** The rule, once the membership is known. */
    private function roleWith(CoreUserInterface $user, NoteSpaceInterface $space, ?NoteSpaceMemberInterface $membership): ?NoteSpaceRoleEnum
    {
        if ($space->getDeletedAt() instanceof DateTimeImmutable) {
            return null;
        }

        if ($space->isPersonal()) {
            return $space->getPersonalUser()?->getId() === $user->getId() ? NoteSpaceRoleEnum::Manager : null;
        }

        if ($this->isOwner($user, $space) || $this->adopts($user, $space)) {
            return NoteSpaceRoleEnum::Manager;
        }

        return match ($space->getAccess()) {
            NoteSpaceAccessEnum::Private => null,
            NoteSpaceAccessEnum::Members => $membership?->getRole(),
            NoteSpaceAccessEnum::Backoffice => $membership instanceof NoteSpaceMemberInterface
                ? $membership->getRole()->atLeast($space->getDefaultRole())
                : $space->getDefaultRole(),
        };
    }

    public function isOwner(CoreUserInterface $user, NoteSpaceInterface $space): bool
    {
        return $space->getOwner() instanceof CoreUserInterface && $space->getOwner()->getId() === $user->getId();
    }

    /**
     * A shared space whose owner has left falls to the administrators.
     *
     * Without this rule, it stayed readable but nobody could configure it,
     * add someone to it or bring it back from the trash any more. It is the
     * only exception to the content principle: it never touches a personal
     * space, which leaves with its account.
     */
    public function adopts(CoreUserInterface $user, NoteSpaceInterface $space): bool
    {
        return !$space->isPersonal() && !$space->getOwner() instanceof CoreUserInterface && NoteSpaceRepository::isAdmin($user);
    }

    public function canRead(CoreUserInterface $user, NoteSpaceInterface $space): bool
    {
        return $this->roleIn($user, $space) instanceof NoteSpaceRoleEnum;
    }

    public function canWrite(CoreUserInterface $user, NoteSpaceInterface $space): bool
    {
        return true === $this->roleIn($user, $space)?->canWrite();
    }

    public function canManage(CoreUserInterface $user, NoteSpaceInterface $space): bool
    {
        return true === $this->roleIn($user, $space)?->canManage();
    }

    public function canCreateShared(): bool
    {
        return $this->authorization->isGranted(self::CREATE);
    }

    public function canPublish(CoreUserInterface $user, NoteSpaceInterface $space): bool
    {
        return !$space->isPersonal() && $this->canManage($user, $space) && $this->authorization->isGranted(self::PUBLISH);
    }

    public function canReadNote(CoreUserInterface $user, MarkdownNoteInterface $note): bool
    {
        return $this->canRead($user, $note->getSpace());
    }

    public function canWriteNote(CoreUserInterface $user, MarkdownNoteInterface $note): bool
    {
        return $this->canWrite($user, $note->getSpace());
    }

    public function canWriteFolder(CoreUserInterface $user, NoteFolderInterface $folder): bool
    {
        return $this->canWrite($user, $folder->getSpace());
    }

    /** The note, not trashed, if the person can read it. */
    public function readableNote(CoreUserInterface $user, int $id): ?MarkdownNoteInterface
    {
        $note = $this->notes->findOneLiving($id);

        return $note instanceof MarkdownNoteInterface && $this->canReadNote($user, $note) ? $note : null;
    }

    /**
     * The note if the person can write it - trash included: restoring or
     * deleting for good is a write too.
     */
    public function writableNote(CoreUserInterface $user, int $id): ?MarkdownNoteInterface
    {
        $note = $this->notes->find($id);

        return $note instanceof MarkdownNoteInterface && $this->canWriteNote($user, $note) ? $note : null;
    }

    public function readableFolder(CoreUserInterface $user, int $id): ?NoteFolderInterface
    {
        $folder = $this->folders->find($id);

        return $folder instanceof NoteFolderInterface && !$folder->isTrashed() && $this->canRead($user, $folder->getSpace()) ? $folder : null;
    }

    public function writableFolder(CoreUserInterface $user, int $id): ?NoteFolderInterface
    {
        $folder = $this->folders->find($id);

        return $folder instanceof NoteFolderInterface && $this->canWriteFolder($user, $folder) ? $folder : null;
    }

    public function readableSpace(CoreUserInterface $user, int $id): ?NoteSpaceInterface
    {
        $space = $this->spaces->find($id);

        return $space instanceof NoteSpaceInterface && $this->canRead($user, $space) ? $space : null;
    }

    public function writableSpace(CoreUserInterface $user, int $id): ?NoteSpaceInterface
    {
        $space = $this->spaces->find($id);

        return $space instanceof NoteSpaceInterface && $this->canWrite($user, $space) ? $space : null;
    }

    public function managedSpace(CoreUserInterface $user, int $id): ?NoteSpaceInterface
    {
        $space = $this->spaces->find($id);

        return $space instanceof NoteSpaceInterface && $this->canManage($user, $space) ? $space : null;
    }

    /**
     * A person's personal space, created the first time it is asked for.
     *
     * On demand rather than at account creation: an account without the
     * module has no reason to carry an empty notebook, and the migration
     * already gave one to every person who had notes.
     */
    public function personalSpace(CoreUserInterface $user): NoteSpaceInterface
    {
        $space = $this->spaces->findPersonalFor($user);

        if ($space instanceof NoteSpaceInterface) {
            return $space;
        }

        $space = new NoteSpace();
        $space->setPersonalUser($user)->setOwner($user)->setAccess(NoteSpaceAccessEnum::Private);

        $this->entityManager->persist($space);
        $this->entityManager->flush();

        return $space;
    }
}
