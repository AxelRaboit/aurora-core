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
 * The life cycle of a space and its memberships.
 *
 * Who may do what is decided beforehand, in the controller, by
 * {@see NoteSpaceAccess}: this manager
 * writes, it does not judge.
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
     * A space configured by something else: without an owner, open to its
     * members only, whom {@see self::syncManaged()} keeps up to date.
     *
     * Without an owner, because nobody owns it: its access follows a team
     * defined elsewhere, and the first member added has no reason to become
     * its master the day they leave that team.
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
     * The name and the members of a space configured elsewhere, reset to
     * what it is given.
     *
     * Reconciled rather than emptied and rebuilt, like a client space's team:
     * a membership that stays keeps its row, and only its role moves. Nothing
     * is written, and nothing enters the audit log, when nothing changes -
     * the sync runs on every save of whatever configures it.
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
     * What configured the space is gone: it goes to the trash, and becomes an
     * ordinary space again.
     *
     * Without an owner, it falls to the administrators, who can bring it back
     * ({@see NoteSpaceAccess::adopts()}). Its members stay members: restoring
     * it gives the space back to those who wrote in it.
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
     * The personal space is not renamed and opens to nobody: it only keeps
     * its colour and its place. The rest is ignored rather than refused, like
     * a folder's cycle: an extension point that throws on a hostile payload
     * is one nobody can override safely.
     */
    protected function applyInput(NoteSpaceInterface $space, NoteSpaceInputInterface $input): void
    {
        $space->setColor($input->getColor());

        if (null !== $input->getPosition()) {
            $space->setPosition($input->getPosition());
        }

        // A space configured elsewhere keeps its name and its access: the
        // controller already refuses, and this holds for a call that would
        // go around it.
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
     * What the audit log keeps of a space: not its name, encrypted for the
     * same reason as a folder's.
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
