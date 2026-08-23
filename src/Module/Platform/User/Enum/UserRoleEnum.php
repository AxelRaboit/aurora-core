<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Enum;

enum UserRoleEnum: string
{
    case User = 'ROLE_USER';
    case Admin = 'ROLE_ADMIN';
    case Dev = 'ROLE_DEV';

    public function priority(): int
    {
        return match ($this) {
            self::Dev => 100,
            self::Admin => 80,
            self::User => 0,
        };
    }

    public function getLabelKey(): string
    {
        return match ($this) {
            self::User => 'backend.users.role.user',
            self::Admin => 'backend.users.role.admin',
            self::Dev => 'backend.users.role.dev',
        };
    }

    /**
     * Returns the highest priority among the given role strings.
     *
     * @param string[] $roles
     */
    public static function highestPriorityForRoles(array $roles): int
    {
        $highest = 0;
        foreach (self::cases() as $role) {
            if (in_array($role->value, $roles, true)) {
                $highest = max($highest, $role->priority());
            }
        }

        return $highest;
    }

    /**
     * Roles that admins can assign to other users (Dev excluded - only Dev can self-assign Dev).
     *
     * @return list<self>
     */
    public static function selectableForAdmin(): array
    {
        return [self::Admin, self::User];
    }

    /**
     * @return list<string>
     */
    public static function selectableForAdminValues(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::selectableForAdmin());
    }

    /**
     * All roles that can be assigned via the admin UI (Dev included).
     *
     * @return list<string>
     */
    public static function allAssignableValues(): array
    {
        return [self::Dev->value, ...self::selectableForAdminValues()];
    }
}
