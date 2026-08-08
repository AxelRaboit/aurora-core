<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Manager;

use Aurora\Core\Locale\Enum\LocaleEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;

interface UserManagerInterface
{
    public function create(string $name, string $email, string $password, bool $isAdmin = true): User;

    public function register(string $name, string $email, string $password): User;

    public function sendVerificationEmail(User $user): void;

    public function verifyEmail(string $token): ?CoreUserInterface;

    public function resendVerificationEmail(string $email): void;

    public function update(User $user, string $name, string $email): void;

    public function updateWithRole(User $user, string $name, string $email, string $role, ?string $password = null): void;

    public function toggleDevRole(User $user): bool;

    public function toggleDisabled(User $user): bool;

    public function changePassword(User $user, string $newPassword): void;

    public function changeLocaleEnum(User $user, LocaleEnum $locale): void;

    public function changeMoodMessage(User $user, ?string $moodMessage): void;

    /** @param list<string> $privileges */
    public function updatePrivileges(User $user, array $privileges): void;

    /**
     * Replaces the per-user module access mask. Each value must be a valid
     * ModuleParameterEnum::value; unknown values are silently filtered.
     *
     * SECURITY: callers reachable from HTTP MUST pass the authenticated user
     * as `$actor`. When `$actor` is non-null, the actor's rank must be ≥ the
     * target user's rank (enforced via `canActOn`). `$actor = null` is
     * reserved for internal / system flows (e.g. fixtures, CLI, migrations)
     * where authorization is already guaranteed upstream — never default
     * `$actor` to null in a controller action.
     *
     * @param list<string> $disabledModules
     */
    public function updateDisabledModules(User $user, array $disabledModules, ?User $actor = null): void;

    /**
     * Replaces the user's personal sidemenu visibility + colouring preferences.
     * Each list is sanitized against the user's current resolved nav catalog:
     * unknown / privilege-filtered entries are silently dropped. This is a
     * user-initiated action — the target user is always the actor themselves
     * (no rank check).
     */
    /**
     * Whether this user keeps the sidemenu collapsed. Beside their hidden
     * sections and section colours rather than in the browser, so the choice
     * follows the account and the layout can render it on first paint.
     */
    public function updateSidemenuCollapsed(User $user, bool $collapsed): void;

    public function updateSidemenuPreferences(
        User $user,
        array $hiddenNavSections,
        array $hiddenNavItems,
        array $navSectionColors = [],
    ): void;

    public function resetSidemenuPreferences(User $user): void;

    public function delete(User $user): void;

    public function isPasswordValid(User $user, string $plainPassword): bool;

    public function isEmailTaken(string $email, ?User $excludeUser = null): bool;

    public function invite(string $name, string $email, string $role, ?string $customMessage): User;

    public function resendInvitation(User $user, ?string $customMessage): void;

    public function consumeInvitation(User $user, string $plainPassword): void;

    public function findValidInvitation(string $selector, string $token): ?User;

    public function canActOn(User $actor, User $target): bool;
}
