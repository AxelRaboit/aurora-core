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
     * The one colour this role wears, everywhere it is shown.
     *
     * Four places used to decide this independently and none of them agreed: the
     * users list painted every role but Dev the same indigo, the profile page had
     * violet/indigo/slate, the dev module had indigo-or-grey, and the dashboard
     * chart got its colours from the order the roles happen to be declared in.
     * A reader learning that orange means admin on one screen learnt nothing
     * about the next one.
     *
     * Blue, orange, green - which is what the dashboard was already showing, so
     * the rest of the app follows the screen that got it right rather than the
     * other way round.
     *
     * The badge preset and the chart slot live in the same method on purpose.
     * They are one decision said in two vocabularies - `AppBadge`'s presets and
     * `--chart-cat-*` - and split across two methods they would drift the first
     * time one was edited alone.
     *
     * @return array{badge: string, slot: int}
     */
    public function colour(): array
    {
        return match ($this) {
            self::User => ['badge' => 'sky', 'slot' => 1],
            self::Admin => ['badge' => 'amber', 'slot' => 2],
            self::Dev => ['badge' => 'emerald', 'slot' => 3],
        };
    }

    public function badgeColor(): string
    {
        return $this->colour()['badge'];
    }

    /**
     * Which `--chart-cat-*` slot this role takes. Asked for explicitly rather
     * than left to the order a provider happens to build its list in: colour
     * follows the entity, and a filter that drops a role must not repaint the
     * ones that are left.
     */
    public function chartSlot(): int
    {
        return $this->colour()['slot'];
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
