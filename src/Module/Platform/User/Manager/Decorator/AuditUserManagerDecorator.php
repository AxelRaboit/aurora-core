<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Manager\Decorator;

use Aurora\Core\Locale\Enum\LocaleEnum;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Manager\UserManagerInterface;
use Aurora\Module\Platform\User\Service\UserNotificationService;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

#[AsDecorator(decorates: UserManagerInterface::class)]
final readonly class AuditUserManagerDecorator implements UserManagerInterface
{
    public function __construct(
        #[AutowireDecorated]
        private UserManagerInterface $inner,
        private AuditLogger $auditLogger,
        private UserNotificationService $notificationService,
    ) {}

    public function create(string $name, string $email, string $password, bool $isAdmin = true): User
    {
        $user = $this->inner->create($name, $email, $password, $isAdmin);
        $this->auditLogger->log('core', 'user.created', 'User', $user->getId(), ['email' => $email, 'isAdmin' => $isAdmin]);

        return $user;
    }

    public function register(string $name, string $email, string $password): User
    {
        $user = $this->inner->register($name, $email, $password);
        $this->auditLogger->log('core', 'user.registered', 'User', $user->getId(), ['email' => $email]);

        return $user;
    }

    public function sendVerificationEmail(User $user): void
    {
        $this->inner->sendVerificationEmail($user);
    }

    public function verifyEmail(string $token): ?CoreUserInterface
    {
        $user = $this->inner->verifyEmail($token);

        if ($user instanceof User) {
            $this->auditLogger->log('core', 'user.email_verified', 'User', $user->getId(), ['email' => $user->getEmail()]);
        }

        return $user;
    }

    public function resendVerificationEmail(string $email): void
    {
        $this->inner->resendVerificationEmail($email);
    }

    public function update(User $user, string $name, string $email): void
    {
        $this->inner->update($user, $name, $email);
        $this->auditLogger->log('core', 'user.updated', 'User', $user->getId(), ['email' => $email]);
    }

    public function updateWithRole(User $user, string $name, string $email, string $role, ?string $password = null): void
    {
        $previousRoles = $user->getRoles();
        $this->inner->updateWithRole($user, $name, $email, $role, $password);
        $this->auditLogger->log('core', 'user.role_updated', 'User', $user->getId(), ['email' => $email, 'role' => $role]);

        if (!in_array($role, $previousRoles, true)) {
            $this->notificationService->notifyRoleChanged($user, $role);
        }
    }

    public function toggleDevRole(User $user): bool
    {
        $result = $this->inner->toggleDevRole($user);
        $this->auditLogger->log('core', 'user.dev_role_toggled', 'User', $user->getId(), ['email' => $user->getEmail(), 'devRoleEnabled' => $result]);

        $this->notificationService->notifyRoleChanged($user, ($result ? UserRoleEnum::Dev : UserRoleEnum::Admin)->value);

        return $result;
    }

    public function toggleDisabled(User $user): bool
    {
        $result = $this->inner->toggleDisabled($user);
        $this->auditLogger->log('core', 'user.disabled_toggled', 'User', $user->getId(), ['email' => $user->getEmail(), 'disabled' => !$result]);

        return $result;
    }

    public function changePassword(User $user, string $newPassword): void
    {
        $this->inner->changePassword($user, $newPassword);
        $this->auditLogger->log('core', 'user.password_changed', 'User', $user->getId(), ['email' => $user->getEmail()]);
    }

    public function changeLocaleEnum(User $user, LocaleEnum $locale): void
    {
        $this->inner->changeLocaleEnum($user, $locale);
    }

    public function changeMoodMessage(User $user, ?string $moodMessage): void
    {
        $this->inner->changeMoodMessage($user, $moodMessage);
    }

    public function delete(User $user): void
    {
        $id = $user->getId();
        $email = $user->getEmail();
        $this->inner->delete($user);
        $this->auditLogger->log('core', 'user.deleted', 'User', $id, ['email' => $email]);
    }

    public function isPasswordValid(User $user, string $plainPassword): bool
    {
        return $this->inner->isPasswordValid($user, $plainPassword);
    }

    public function isEmailTaken(string $email, ?User $excludeUser = null): bool
    {
        return $this->inner->isEmailTaken($email, $excludeUser);
    }

    public function invite(string $name, string $email, string $role, ?string $customMessage): User
    {
        $user = $this->inner->invite($name, $email, $role, $customMessage);
        $this->auditLogger->log('core', 'user.invited', 'User', $user->getId(), ['email' => $email, 'role' => $role]);

        return $user;
    }

    public function resendInvitation(User $user, ?string $customMessage): void
    {
        $this->inner->resendInvitation($user, $customMessage);
        $this->auditLogger->log('core', 'user.invitation_resent', 'User', $user->getId(), ['email' => $user->getEmail()]);
    }

    public function consumeInvitation(User $user, string $plainPassword): void
    {
        $this->inner->consumeInvitation($user, $plainPassword);
        $this->auditLogger->log('core', 'user.invitation_consumed', 'User', $user->getId(), ['email' => $user->getEmail()]);
    }

    public function findValidInvitation(string $selector, string $token): ?User
    {
        return $this->inner->findValidInvitation($selector, $token);
    }

    public function updatePrivileges(User $user, array $privileges): void
    {
        $this->inner->updatePrivileges($user, $privileges);

        $this->auditLogger->log('core', 'user.privileges_updated', 'User', $user->getId(), [
            'name' => $user->getName(),
            'privileges' => $privileges,
        ]);
    }

    public function updateDisabledModules(User $user, array $disabledModules, ?User $actor = null): void
    {
        $this->inner->updateDisabledModules($user, $disabledModules, $actor);

        $this->auditLogger->log('core', 'user.disabled_modules_updated', 'User', $user->getId(), [
            'name' => $user->getName(),
            'disabledModules' => $user->getDisabledModules(),
            'actorId' => $actor?->getId(),
        ]);
    }

    /**
     * Not audited. Folding a menu is a gesture, not a change to the account
     * worth a trail entry — one afternoon of clicks would bury the entries
     * that matter.
     */
    public function updateSidemenuCollapsed(User $user, bool $collapsed): void
    {
        $this->inner->updateSidemenuCollapsed($user, $collapsed);
    }

    public function updateSidemenuShowDescriptions(User $user, bool $show): void
    {
        $this->inner->updateSidemenuShowDescriptions($user, $show);
    }

    public function updateSidemenuPreferences(
        User $user,
        array $hiddenNavSections,
        array $hiddenNavItems,
        array $navSectionColors = [],
    ): void {
        $this->inner->updateSidemenuPreferences($user, $hiddenNavSections, $hiddenNavItems, $navSectionColors);

        $this->auditLogger->log('core', 'user.sidemenu_preferences_updated', 'User', $user->getId(), [
            'hiddenNavSections' => $user->getHiddenNavSections(),
            'hiddenNavItems' => $user->getHiddenNavItems(),
            'navSectionColors' => $user->getNavSectionColors(),
        ]);
    }

    public function resetSidemenuPreferences(User $user): void
    {
        $this->inner->resetSidemenuPreferences($user);

        $this->auditLogger->log('core', 'user.sidemenu_preferences_reset', 'User', $user->getId(), []);
    }

    public function canActOn(User $actor, User $target): bool
    {
        return $this->inner->canActOn($actor, $target);
    }
}
