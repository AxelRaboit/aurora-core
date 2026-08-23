<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Dashboard;

use Aurora\Core\Dashboard\DashboardStatsProviderInterface;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;

/**
 * Platform's figures on the backend dashboard: who has an account.
 *
 * Scoped to backend users, which is what the module's own list manages - a
 * dashboard that counted the public site's readers here would answer a question
 * nobody asked from this page.
 */
final readonly class PlatformStatsProvider implements DashboardStatsProviderInterface
{
    public function __construct(
        private UserRepository $userRepository,
    ) {}

    public function getModuleKey(): string
    {
        return 'platform';
    }

    public function getStats(): array
    {
        $byRole = $this->countByEffectiveRole();

        return [
            'platform' => [
                'users' => array_sum($byRole),
                'byRole' => $byRole,
            ],
        ];
    }

    /**
     * Users under the role their badge shows, not under every role they hold.
     *
     * A share chart on overlapping sets is a lie: its parts would sum past the
     * whole. So each stored roles array is folded to its single highest role,
     * through the same {@see UserRoleEnum::highestPriorityForRoles()} the
     * serializer uses for the badge - if this counted differently, the dashboard
     * and the users list would disagree about the same person.
     *
     * @return array<string, int>
     */
    private function countByEffectiveRole(): array
    {
        $counts = [];
        foreach (UserRoleEnum::cases() as $role) {
            $counts[$role->value] = 0;
        }

        foreach ($this->userRepository->countGroupedByStoredRoles() as $storedRoles => $count) {
            $roles = json_decode($storedRoles, true);
            if (!is_array($roles)) {
                continue;
            }

            // `getRoles()` appends ROLE_USER on the way out of the entity, so a
            // stored array of just that is a plain user; anything else outranks
            // it and wins here for the same reason it wins on the badge.
            $priority = UserRoleEnum::highestPriorityForRoles(
                array_values(array_filter($roles, is_string(...))),
            );

            foreach (UserRoleEnum::cases() as $role) {
                if ($role->priority() === $priority) {
                    $counts[$role->value] += $count;

                    break;
                }
            }
        }

        return $counts;
    }
}
