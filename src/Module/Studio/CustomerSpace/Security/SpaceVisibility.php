<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Security;

use Aurora\Core\Module\Security\ModulePermissionVoter;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Module\Studio\CustomerSpace\Enum\SpaceScopeEnum;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Which spaces a person sees, and which ones they administer.
 *
 * **A single answer to "do they see everything"**, and that is this
 * service's reason to exist. Three screens ask the question, and three
 * copies would have ended up answering differently the day a fourth role
 * appears.
 *
 * The privilege says *what one can do*, membership says *on what*. The two
 * add up: a teammate without the edit privilege does not edit, even on their
 * own space; a teammate who has it only edits their own.
 *
 * Administrator and developer already bypass every privilege in
 * {@see ModulePermissionVoter}; they
 * bypass membership here, so both halves of the model say the same
 * thing.
 */
final readonly class SpaceVisibility
{
    public function __construct(
        private Security $security,
        private CustomerSpaceRepository $spaces,
    ) {}

    /**
     * Do they see every space, without being a member of any?
     *
     * The two roles that go above privileges, and only those.
     */
    public function seesAll(): bool
    {
        if ($this->security->isGranted(UserRoleEnum::Dev->value)) {
            return true;
        }

        return $this->security->isGranted(UserRoleEnum::Admin->value);
    }

    /** @return list<CustomerSpaceInterface> */
    public function visibleSpaces(): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof CoreUserInterface) {
            return [];
        }

        return $this->spaces->findVisibleTo($user, $this->seesAll());
    }

    /**
     * The spaces a cross-space screen shows, for this scope.
     *
     * « Mine » is the spaces the reader is a member of. Somebody who sees
     * every space but is a member of none gets every space instead: their own
     * would be an empty screen, and they are the one person for whom « all »
     * is the natural answer.
     *
     * @return list<CustomerSpaceInterface>
     */
    public function spacesIn(SpaceScopeEnum $scope): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof CoreUserInterface) {
            return [];
        }

        $mine = $this->spaces->findVisibleTo($user, false);

        if (!$this->seesAll()) {
            return $mine;
        }

        return SpaceScopeEnum::Mine === $scope && [] !== $mine ? $mine : $this->spaces->findVisibleTo($user, true);
    }

    /**
     * Whether « mine » and « all » are two different answers for this reader,
     * so the screen should offer the switch at all.
     */
    public function hasScopeChoice(): bool
    {
        $user = $this->security->getUser();

        return $this->seesAll() && $user instanceof CoreUserInterface && [] !== $this->spaces->findVisibleTo($user, false);
    }

    /**
     * Do they see this space?
     *
     * A space in the trash is seen by nobody: its screens, its deliverables
     * and its search answer as for an unknown space. Only the trash screen
     * shows it, through {@see self::reaches()}.
     */
    public function canSee(CustomerSpaceInterface $space): bool
    {
        return !$space->isTrashed() && $this->reaches($space);
    }

    /**
     * Is it one of theirs, whether alive or in the trash?
     *
     * The "membership" half of {@see self::canSee()}: the trash shows each
     * person the spaces of their team that were put there, and to whoever
     * sees everything, all of them.
     */
    public function reaches(CustomerSpaceInterface $space): bool
    {
        if ($this->seesAll()) {
            return true;
        }

        $user = $this->security->getUser();

        return $user instanceof CoreUserInterface
            && $this->spaces->isVisibleTo($space, $user, false);
    }

    /**
     * Can they open this space's settings?
     *
     * **This is what finally gives the lead role a meaning.** Until now it
     * only said who to talk to; it now opens a door their teammates do not
     * have. The rest of the work in the space does not change: a teammate
     * writes, comments and schedules as before.
     */
    public function canConfigure(CustomerSpaceInterface $space): bool
    {
        if ($this->seesAll()) {
            return true;
        }

        $user = $this->security->getUser();

        if (!$user instanceof CoreUserInterface) {
            return false;
        }

        foreach ($space->getMembers() as $member) {
            if ($member->getUser() === $user && CustomerSpaceMemberRoleEnum::Lead === $member->getRole()) {
                return true;
            }
        }

        return false;
    }
}
