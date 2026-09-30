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
 * Le rôle d'une personne dans un espace, et tout ce qui en découle.
 *
 * **Un seul endroit décide.** Chaque route qui lit ou écrit une note demande
 * ici ; aucune ne charge plus rien « par propriétaire ». La règle :
 *
 * - son espace personnel, et ceux dont on est propriétaire : gestionnaire ;
 * - un espace ouvert aux membres : le rôle de son inscription ;
 * - un espace ouvert à tout le back-office : le rôle par défaut, ou celui de
 *   son inscription s'il est plus fort ;
 * - sinon, rien : l'espace n'existe pas pour la personne.
 *
 * Pas de passe-droit pour les administrateurs sur le **contenu** : ils ont
 * les droits du module (créer, publier), pas les carnets privés des autres.
 * Le carnet de quelqu'un reste le sien.
 */
final readonly class NoteSpaceAccess
{
    /** Créer un espace partagé - son espace personnel ne demande rien. */
    public const string CREATE = 'notes.spaces.create';

    /** Publier un espace sur le web, en lecture sans connexion. */
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
     * Le rôle d'une personne dans plusieurs espaces, en une requête.
     *
     * Pour une liste : `roleIn()` cherche l'inscription espace par espace, ce
     * qui ferait une requête par ligne.
     *
     * @param list<NoteSpaceInterface> $spaces
     *
     * @return array<int, NoteSpaceRoleEnum> identifiant d'espace => rôle, sans les espaces fermés à la personne
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

    /** La règle, une fois l'inscription connue. */
    private function roleWith(CoreUserInterface $user, NoteSpaceInterface $space, ?NoteSpaceMemberInterface $membership): ?NoteSpaceRoleEnum
    {
        if ($space->getDeletedAt() instanceof DateTimeImmutable) {
            return null;
        }

        if ($space->isPersonal()) {
            return $space->getPersonalUser()?->getId() === $user->getId() ? NoteSpaceRoleEnum::Manager : null;
        }

        if ($this->isOwner($user, $space)) {
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

    /** La note, vivante, si la personne peut la lire. */
    public function readableNote(CoreUserInterface $user, int $id): ?MarkdownNoteInterface
    {
        $note = $this->notes->findOneLiving($id);

        return $note instanceof MarkdownNoteInterface && $this->canReadNote($user, $note) ? $note : null;
    }

    /**
     * La note si la personne peut l'écrire - corbeille comprise : restaurer
     * ou supprimer pour de bon est aussi une écriture.
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
     * L'espace personnel d'une personne, créé la première fois qu'on le
     * demande.
     *
     * À la demande plutôt qu'à la création du compte : un compte sans le
     * module n'a pas à porter un carnet vide, et la migration a déjà donné le
     * sien à chaque personne qui avait des notes.
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
