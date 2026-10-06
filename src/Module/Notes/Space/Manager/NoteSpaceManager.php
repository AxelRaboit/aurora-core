<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Manager;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Notes\Space\Dto\NoteSpaceInputInterface;
use Aurora\Module\Notes\Space\Entity\NoteSpace;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Entity\NoteSpaceMember;
use Aurora\Module\Notes\Space\Entity\NoteSpaceMemberInterface;
use Aurora\Module\Notes\Space\Enum\NoteSpaceAccessEnum;
use Aurora\Module\Notes\Space\Enum\NoteSpaceRoleEnum;
use Aurora\Module\Notes\Space\Repository\NoteSpaceRepository;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Le cycle de vie d'un espace et de ses inscriptions.
 *
 * Qui a le droit de faire quoi se décide avant, dans le contrôleur, par
 * {@see NoteSpaceAccess} : ce manager
 * écrit, il ne juge pas.
 */
#[AsAlias(NoteSpaceManagerInterface::class)]
class NoteSpaceManager implements NoteSpaceManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly NoteSpaceRepository $spaceRepository,
        protected readonly AuditLogger $auditLogger,
    ) {}

    public function create(CoreUserInterface $owner, NoteSpaceInputInterface $input): NoteSpaceInterface
    {
        $space = $this->createSpace();
        $space->setOwner($owner);
        $this->applyInput($space, $input);

        $this->entityManager->persist($space);
        $this->entityManager->flush();

        $this->auditCreated($space);

        return $space;
    }

    /**
     * Un espace que quelque chose d'autre règle : sans propriétaire, ouvert à
     * ses seuls membres, que {@see self::syncManaged()} tient à jour.
     *
     * Sans propriétaire, parce que personne n'en est le propriétaire : son
     * accès suit une équipe définie ailleurs, et le premier membre inscrit
     * n'a pas à en devenir le maître le jour où il quitte cette équipe.
     */
    public function createManaged(string $name, string $managedBy): NoteSpaceInterface
    {
        $space = $this->createSpace();
        $space
            ->setName($name)
            ->setManagedBy($managedBy)
            ->setAccess(NoteSpaceAccessEnum::Members)
            ->setDefaultRole(NoteSpaceRoleEnum::Reader);

        $this->entityManager->persist($space);
        $this->entityManager->flush();

        $this->auditCreated($space);

        return $space;
    }

    /**
     * Le nom et les membres d'un espace réglé d'ailleurs, remis sur ce qu'on
     * lui donne.
     *
     * Réconcilié plutôt que vidé et reconstruit, comme l'équipe d'un espace
     * client : une inscription qui reste garde sa ligne, et seul son rôle
     * bouge. Rien n'est écrit, et rien n'entre au journal, quand rien ne
     * change - la synchronisation passe à chaque enregistrement de ce qui la
     * règle.
     */
    public function syncManaged(NoteSpaceInterface $space, string $name, array $members): void
    {
        $changed = $space->getName() !== $name;
        $space->setName($name);

        $wanted = [];
        foreach ($members as $member) {
            $wanted[(int) $member['user']->getId()] = $member;
        }

        foreach ($space->getMembers()->toArray() as $membership) {
            $userId = (int) $membership->getUser()->getId();

            if (!isset($wanted[$userId])) {
                $space->getMembers()->removeElement($membership);
                $this->entityManager->remove($membership);
                $changed = true;

                continue;
            }

            if ($membership->getRole() !== $wanted[$userId]['role']) {
                $membership->setRole($wanted[$userId]['role']);
                $changed = true;
            }

            unset($wanted[$userId]);
        }

        foreach ($wanted as $member) {
            $membership = $this->createMember();
            $membership->setSpace($space)->setUser($member['user'])->setRole($member['role']);
            $space->getMembers()->add($membership);
            $this->entityManager->persist($membership);
            $changed = true;
        }

        if (!$changed) {
            return;
        }

        $this->entityManager->flush();

        $this->auditUpdated($space);
    }

    /**
     * Ce qui réglait l'espace a disparu : il part à la corbeille, et redevient
     * un espace ordinaire.
     *
     * Sans propriétaire, il revient aux administrateurs, qui peuvent le faire
     * revenir ({@see NoteSpaceAccess::adopts()}). Ses membres restent inscrits :
     * le restaurer rend l'espace à ceux qui y écrivaient.
     */
    public function releaseManaged(NoteSpaceInterface $space): void
    {
        $space->setManagedBy(null);

        if (!$space->getDeletedAt() instanceof DateTimeImmutable) {
            $space->setDeletedAt(new DateTimeImmutable());
        }

        $this->entityManager->flush();

        $this->auditDeleted($space);
    }

    public function update(NoteSpaceInterface $space, NoteSpaceInputInterface $input): void
    {
        $this->applyInput($space, $input);
        $this->entityManager->flush();

        $this->auditUpdated($space);
    }

    public function delete(NoteSpaceInterface $space): void
    {
        $space->setDeletedAt(new DateTimeImmutable());
        $this->entityManager->flush();

        $this->auditDeleted($space);
    }

    public function restore(NoteSpaceInterface $space): void
    {
        $space->setDeletedAt(null);
        $this->entityManager->flush();

        $this->auditUpdated($space);
    }

    public function publish(NoteSpaceInterface $space, string $slug, bool $indexable): void
    {
        $space->setSlug($slug)->setIndexable($indexable);

        if (!$space->getPublishedAt() instanceof DateTimeImmutable) {
            $space->setPublishedAt(new DateTimeImmutable());
        }

        $this->entityManager->flush();

        $this->auditLogger->log('notes_markdown', 'space.published', 'NoteSpace', $space->getId(), $this->auditPayload($space));
    }

    public function unpublish(NoteSpaceInterface $space): void
    {
        $space->setPublishedAt(null);
        $this->entityManager->flush();

        $this->auditLogger->log('notes_markdown', 'space.unpublished', 'NoteSpace', $space->getId(), $this->auditPayload($space));
    }

    public function setMember(NoteSpaceInterface $space, CoreUserInterface $user, NoteSpaceRoleEnum $role): NoteSpaceMemberInterface
    {
        $member = $this->spaceRepository->findMembership($space, $user);

        if (!$member instanceof NoteSpaceMemberInterface) {
            $member = $this->createMember();
            $member->setSpace($space)->setUser($user);
            $this->entityManager->persist($member);
        }

        $member->setRole($role);
        $this->entityManager->flush();

        $this->auditUpdated($space);

        return $member;
    }

    public function removeMember(NoteSpaceInterface $space, CoreUserInterface $user): bool
    {
        $member = $this->spaceRepository->findMembership($space, $user);

        if (!$member instanceof NoteSpaceMemberInterface) {
            return false;
        }

        $this->entityManager->remove($member);
        $this->entityManager->flush();

        $this->auditUpdated($space);

        return true;
    }

    protected function createSpace(): NoteSpaceInterface
    {
        return new NoteSpace();
    }

    protected function createMember(): NoteSpaceMemberInterface
    {
        return new NoteSpaceMember();
    }

    /**
     * L'espace personnel ne se renomme pas et ne s'ouvre à personne : il
     * garde seulement sa couleur et sa place. Le reste est ignoré plutôt
     * que refusé, comme le cycle d'un dossier : un point d'extension qui
     * lève sur un envoi hostile, personne ne peut le surcharger sans risque.
     */
    protected function applyInput(NoteSpaceInterface $space, NoteSpaceInputInterface $input): void
    {
        $space->setColor($input->getColor());

        if (null !== $input->getPosition()) {
            $space->setPosition($input->getPosition());
        }

        // Un espace réglé d'ailleurs garde son nom et son accès : le
        // contrôleur refuse déjà, et ceci tient pour un appel qui passerait à
        // côté de lui.
        if ($space->isPersonal() || $space->isManaged()) {
            return;
        }

        $space->setName($input->getName());
        $space->setAccess(NoteSpaceAccessEnum::tryFrom((string) $input->getAccess()) ?? $space->getAccess());
        $space->setDefaultRole(NoteSpaceRoleEnum::tryFrom((string) $input->getDefaultRole()) ?? $space->getDefaultRole());
    }

    protected function auditCreated(NoteSpaceInterface $space): void
    {
        $this->auditLogger->log('notes_markdown', 'space.created', 'NoteSpace', $space->getId(), $this->auditPayload($space));
    }

    protected function auditUpdated(NoteSpaceInterface $space): void
    {
        $this->auditLogger->log('notes_markdown', 'space.updated', 'NoteSpace', $space->getId(), $this->auditPayload($space));
    }

    protected function auditDeleted(NoteSpaceInterface $space): void
    {
        $this->auditLogger->log('notes_markdown', 'space.deleted', 'NoteSpace', $space->getId(), $this->auditPayload($space));
    }

    /**
     * Ce que le journal garde d'un espace : pas son nom, chiffré pour la même
     * raison que celui d'un dossier.
     *
     * @return array<string, mixed>
     */
    protected function auditPayload(NoteSpaceInterface $space): array
    {
        return [
            'access' => $space->getAccess()->value,
            'defaultRole' => $space->getDefaultRole()->value,
            'members' => $space->getMembers()->count(),
            'deleted' => $space->getDeletedAt() instanceof DateTimeImmutable,
            'published' => $space->isPublished(),
            'indexable' => $space->isIndexable(),
            'managedBy' => $space->getManagedBy(),
        ];
    }
}
