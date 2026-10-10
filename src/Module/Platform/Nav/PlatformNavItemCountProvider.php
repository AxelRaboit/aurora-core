<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Nav;

use Aurora\Core\Module\Nav\NavItemCountProviderInterface;
use Aurora\Module\Platform\User\Repository\UserRepository;

/**
 * The side menu's figure for the accounts: every account the users list
 * shows, suite and public site alike, since the list draws both.
 */
final readonly class PlatformNavItemCountProvider implements NavItemCountProviderInterface
{
    private const string USERS = 'suite_platform_users';

    public function __construct(
        private UserRepository $userRepository,
    ) {}

    public function getCountedItemKeys(): array
    {
        return [self::USERS];
    }

    public function countItem(string $itemKey): int
    {
        return self::USERS === $itemKey ? $this->userRepository->count([]) : 0;
    }
}
