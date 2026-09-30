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

        if ($space->isPersonal()) {
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
        ];
    }
}
