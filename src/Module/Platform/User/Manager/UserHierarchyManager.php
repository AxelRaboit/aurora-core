<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Manager;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(UserHierarchyManagerInterface::class)]
class UserHierarchyManager implements UserHierarchyManagerInterface
{
    /**
     * Hard cap when walking the manager chain to detect cycles. Real org
     * structures rarely exceed a handful of levels; this guard exists only
     * to bound the worst case when the database somehow contains a cycle.
     */
    private const int MAX_HIERARCHY_DEPTH = 50;

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly UserRepository $userRepository,
    ) {}

    /**
     * Assigns the manager and flushes immediately. Use {@see applyManager()} when
     * the assignment is part of a larger transaction (single flush at the end).
     */
    public function setManager(User $user, ?int $managerId): void
    {
        $this->applyManager($user, $managerId);
        $this->entityManager->flush();
    }

    /**
     * Validates and assigns the manager attribute on the in-memory entity, without flushing.
     *
     * @throws InvalidArgumentException with a translation key when the assignment is invalid
     */
    public function applyManager(User $user, ?int $managerId): void
    {
        if (null === $managerId) {
            $user->setManager(null);

            return;
        }

        if ($managerId === $user->getId()) {
            throw new InvalidArgumentException('suite.users.errors.manager_self');
        }

        $manager = $this->userRepository->find($managerId);
        if (!$manager instanceof User) {
            throw new InvalidArgumentException('suite.users.errors.manager_not_found');
        }

        if ($this->wouldCreateCycle($user, $manager)) {
            throw new InvalidArgumentException('suite.users.errors.manager_cycle');
        }

        $user->setManager($manager);
    }

    private function wouldCreateCycle(User $user, User $candidate): bool
    {
        $current = $candidate;
        $depth = 0;
        while ($current instanceof User && $depth < self::MAX_HIERARCHY_DEPTH) {
            if ($current->getId() === $user->getId()) {
                return true;
            }

            $current = $current->getManager();
            ++$depth;
        }

        return false;
    }
}
