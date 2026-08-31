<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Manager;

use Aurora\Core\Locale\Enum\LocaleEnum;
use Aurora\Core\Module\Service\ModuleRegistry;
use Aurora\Core\Module\Toggle\ModuleToggleRegistry;
use Aurora\Core\Sequence\SequenceGenerator;
use Aurora\Core\Sequence\SequencePrefixEnum;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Platform\Auth\Manager\EmailVerificationManagerInterface;
use Aurora\Module\Platform\Auth\Manager\InvitationManagerInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserStatusEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsAlias(UserManagerInterface::class)]
class UserManager implements UserManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly UserRepository $userRepository,
        protected readonly UserPasswordHasherInterface $passwordHasher,
        protected readonly InvitationManagerInterface $invitationManager,
        protected readonly UrlGeneratorInterface $urlGenerator,
        protected readonly EmailVerificationManagerInterface $emailVerificationManager,
        protected readonly SequenceGenerator $sequenceGenerator,
        protected readonly SettingRepository $settingRepository,
        protected readonly ModuleToggleRegistry $moduleToggleRegistry,
        protected readonly ModuleRegistry $moduleRegistry,
    ) {}

    public function create(string $name, string $email, string $password, bool $isAdmin = true): User
    {
        $user = $this->createUser();
        $user->setName($name);
        $user->setEmail($email);
        $user->setType(UserTypeEnum::Backend);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setRoles($isAdmin ? [UserRoleEnum::Admin->value] : []);

        $prefix = $this->settingRepository->get(ApplicationParameterEnum::CoreUserPrefix->value, SequencePrefixEnum::User->value) ?? SequencePrefixEnum::User->value;
        $user->setReference($this->sequenceGenerator->next($prefix));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    public function register(string $name, string $email, string $password): User
    {
        $user = $this->createUser();
        $user->setName($name);
        $user->setEmail($email);
        $user->setType(UserTypeEnum::Backend);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setRoles([UserRoleEnum::Admin->value]);
        $user->setStatus(UserStatusEnum::PendingVerification);

        $prefix = $this->settingRepository->get(ApplicationParameterEnum::CoreUserPrefix->value, SequencePrefixEnum::User->value) ?? SequencePrefixEnum::User->value;
        $user->setReference($this->sequenceGenerator->next($prefix));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->sendVerificationEmail($user);

        return $user;
    }

    public function sendVerificationEmail(User $user): void
    {
        $token = $this->emailVerificationManager->generateToken($user);

        $verifyUrl = $this->urlGenerator->generate('backend_platform_verify_email', [
            'token' => $token,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $this->emailVerificationManager->sendVerificationEmail($user, $verifyUrl);
    }

    public function verifyEmail(string $token): ?CoreUserInterface
    {
        $user = $this->userRepository->findOneBy(['emailVerificationToken' => $token, 'type' => UserTypeEnum::Backend]);
        if (null === $user) {
            return null;
        }

        $expiresAt = $user->getEmailVerificationExpiresAt();
        if (null === $expiresAt || $expiresAt < new DateTimeImmutable()) {
            return null;
        }

        $user->setStatus(UserStatusEnum::Active);
        $user->setEmailVerificationToken(null);
        $user->setEmailVerificationExpiresAt(null);

        $this->entityManager->flush();

        return $user;
    }

    public function resendVerificationEmail(string $email): void
    {
        $user = $this->userRepository->findOneBy([
            'email' => $email,
            'type' => UserTypeEnum::Backend,
        ]);

        if (!$user instanceof User || UserStatusEnum::PendingVerification !== $user->getStatus()) {
            return;
        }

        $this->sendVerificationEmail($user);
    }

    public function update(User $user, string $name, string $email): void
    {
        if ($this->isEmailTaken($email, $user)) {
            throw new InvalidArgumentException('backend.users.errors.email_taken');
        }

        $user->setName($name);
        $user->setEmail($email);

        $this->entityManager->flush();
    }

    public function updateWithRole(User $user, string $name, string $email, string $role, ?string $password = null): void
    {
        if (!in_array($role, UserRoleEnum::allAssignableValues(), true)) {
            throw new InvalidArgumentException('backend.users.errors.role_invalid');
        }

        if ($this->isEmailTaken($email, $user)) {
            throw new InvalidArgumentException('backend.users.errors.email_taken');
        }

        $user->setName($name);
        $user->setEmail($email);
        $user->setRoles([$role]);

        if (null !== $password && '' !== $password) {
            $this->changePassword($user, $password);
        }

        $this->entityManager->flush();
    }

    public function toggleDevRole(User $user): bool
    {
        $hasDev = in_array(UserRoleEnum::Dev->value, $user->getRoles(), true);

        $user->setRoles($hasDev ? [UserRoleEnum::Admin->value] : [UserRoleEnum::Dev->value]);
        $this->entityManager->flush();

        return !$hasDev;
    }

    /**
     * Bascule l'accès d'un compte, et envoie son invitation si c'est la première
     * fois qu'on l'ouvre.
     *
     * `invitedAt` nul veut dire que personne n'a jamais été contacté - le compte
     * a été créé pré-provisionné. L'activer ne peut donc pas le passer `Active` :
     * son mot de passe est un aléa que personne ne connaît, et le compte
     * paraîtrait utilisable sans l'être. Il passe `Invited`, et l'invitation part
     * à ce moment-là.
     *
     * @return bool true si le compte est désormais ouvert
     */
    public function toggleDisabled(User $user): bool
    {
        $isDisabled = UserStatusEnum::Disabled === $user->getStatus();

        if (!$isDisabled) {
            $user->setStatus(UserStatusEnum::Disabled);
            $this->entityManager->flush();

            return false;
        }

        // Le renvoi manuel fait déjà exactement ce qu'il faut : passer `Invited`,
        // émettre un jeton neuf - le jeton en clair n'étant jamais stocké, il n'y
        // a pas d'autre moyen que d'en refaire un - et envoyer le mail.
        if (!$user->getInvitedAt() instanceof DateTimeImmutable) {
            $this->resendInvitation($user, null);

            return true;
        }

        $user->setStatus(UserStatusEnum::Active);
        $this->entityManager->flush();

        return true;
    }

    public function changePassword(User $user, string $newPassword): void
    {
        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
        $this->entityManager->flush();
    }

    public function changeLocaleEnum(User $user, LocaleEnum $locale): void
    {
        $user->setLocale($locale);
        $this->entityManager->flush();
    }

    public function changeMoodMessage(User $user, ?string $moodMessage): void
    {
        $user->setMoodMessage($moodMessage);
        $this->entityManager->flush();
    }

    /** @param list<string> $privileges */
    public function updatePrivileges(User $user, array $privileges): void
    {
        $user->setPrivileges($privileges);
        $this->entityManager->flush();
    }

    /** @param list<string> $disabledModules */
    public function updateDisabledModules(User $user, array $disabledModules, ?User $actor = null): void
    {
        if ($actor instanceof User && !$this->canActOn($actor, $user)) {
            throw new InvalidArgumentException('backend.users.errors.cannot_manage_target');
        }

        $sanitized = $this->sanitizeDisabledModules($disabledModules);

        $user->setDisabledModules($sanitized);
        $this->entityManager->flush();
    }

    /**
     * Filters the incoming list to toggles declared by the registry (core
     * `ModuleParameterEnum` cases + any client module that implements
     * `ModuleToggleProviderInterface`), drops unknowns silently and
     * deduplicates. Callers are expected to pre-filter to strings (the
     * controller does so via `array_filter(..., is_string(...))`).
     *
     * @param list<string> $disabledModules
     *
     * @return list<string>
     */
    protected function sanitizeDisabledModules(array $disabledModules): array
    {
        $known = $this->moduleToggleRegistry->getAll();

        $clean = [];
        foreach ($disabledModules as $entry) {
            if (isset($known[$entry])) {
                $clean[$entry] = true;
            }
        }

        return array_keys($clean);
    }

    public function updateSidemenuCollapsed(User $user, bool $collapsed): void
    {
        $user->setSidemenuCollapsed($collapsed);
        $this->entityManager->flush();
    }

    public function updateSidemenuShowDescriptions(User $user, bool $show): void
    {
        $user->setSidemenuShowDescriptions($show);
        $this->entityManager->flush();
    }

    /**
     * @param list<string>          $hiddenNavSections
     * @param list<string>          $hiddenNavItems
     * @param array<string, string> $navSectionColors  map of sectionId → Tailwind palette name
     */
    public function updateSidemenuPreferences(
        User $user,
        array $hiddenNavSections,
        array $hiddenNavItems,
        array $navSectionColors = [],
    ): void {
        [$validSectionIds, $validItemKeys] = $this->collectKnownNavTokens();

        $user->setHiddenNavSections(array_values(array_intersect($hiddenNavSections, $validSectionIds)));
        $user->setHiddenNavItems(array_values(array_intersect($hiddenNavItems, $validItemKeys)));

        // Filter the colour map to known sectionIds + non-empty values. The
        // controller already enforces `array<string, string>` (cf. signature
        // contract) so we only check emptiness + unknown ids here. Colour
        // names themselves aren't validated - the front falls back to the
        // default palette when a name isn't in its registry.
        $cleanColors = [];
        foreach ($navSectionColors as $sectionId => $colorName) {
            if ('' === $colorName) {
                continue;
            }

            if (!in_array($sectionId, $validSectionIds, true)) {
                continue;
            }

            $cleanColors[$sectionId] = $colorName;
        }

        $user->setNavSectionColors($cleanColors);

        $this->entityManager->flush();
    }

    public function resetSidemenuPreferences(User $user): void
    {
        $user->setHiddenNavSections([]);
        $user->setHiddenNavItems([]);
        $user->setNavSectionColors([]);

        $this->entityManager->flush();
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    protected function collectKnownNavTokens(): array
    {
        $sectionIds = [];
        $itemKeys = [];

        foreach ($this->moduleRegistry->getNavPreferences() as $section) {
            $sectionIds[] = $section['id'];
            $this->walkItemKeys($section['items'], $itemKeys);
        }

        return [$sectionIds, $itemKeys];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param list<string>                     $out
     */
    private function walkItemKeys(array $items, array &$out): void
    {
        foreach ($items as $item) {
            if (isset($item['key']) && is_string($item['key'])) {
                $out[] = $item['key'];
            }

            if (isset($item['children']) && is_array($item['children'])) {
                $this->walkItemKeys($item['children'], $out);
            }
        }
    }

    public function delete(User $user): void
    {
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    public function isPasswordValid(User $user, string $plainPassword): bool
    {
        return $this->passwordHasher->isPasswordValid($user, $plainPassword);
    }

    public function isEmailTaken(string $email, ?User $excludeUser = null): bool
    {
        $existing = $this->userRepository->findOneBy(['email' => $email]);

        if (null === $existing) {
            return false;
        }

        return !$excludeUser instanceof User || $existing->getId() !== $excludeUser->getId();
    }

    /**
     * @param bool $disabled créer le compte sans contacter personne - voir plus bas
     */
    public function invite(string $name, string $email, string $role, ?string $customMessage, bool $disabled = false): User
    {
        if (!in_array($role, UserRoleEnum::allAssignableValues(), true)) {
            throw new InvalidArgumentException('backend.users.errors.role_invalid');
        }

        $user = $this->createUser();
        $user->setName($name);
        $user->setEmail($email);
        $user->setType(UserTypeEnum::Backend);
        $user->setRoles([$role]);
        $user->setStatus($disabled ? UserStatusEnum::Disabled : UserStatusEnum::Invited);
        $user->setLocale(LocaleEnum::French);
        // Un mot de passe que personne ne connaît : il faut bien remplir la
        // colonne, et l'accès se fera par l'invitation.
        $user->setPassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(24))));

        $prefix = $this->settingRepository->get(ApplicationParameterEnum::CoreUserPrefix->value, SequencePrefixEnum::User->value) ?? SequencePrefixEnum::User->value;
        $user->setReference($this->sequenceGenerator->next($prefix));

        /**
         * Un compte pré-provisionné n'émet aucun jeton et n'envoie aucun mail.
         *
         * C'est ce qui laisse `invitedAt` nul, et c'est ce nul qui distingue
         * « jamais contacté » de « désactivé après avoir été actif » - les deux
         * portent le même statut `Disabled`, et sans cette différence la liste
         * les afficherait à l'identique. Émettre un jeton que personne ne
         * recevra le ferait expirer en 48 heures pour rien.
         *
         * L'invitation part quand le compte est activé, cf. toggleDisabled().
         */
        $plainToken = $disabled ? null : $this->prepareInvitationToken($user);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        if (null !== $plainToken) {
            $this->invitationManager->sendInvitation($user, $plainToken, $customMessage);
        }

        return $user;
    }

    public function resendInvitation(User $user, ?string $customMessage): void
    {
        $user->setStatus(UserStatusEnum::Invited);
        $plainToken = $this->prepareInvitationToken($user);

        $this->entityManager->flush();

        $this->invitationManager->sendInvitation($user, $plainToken, $customMessage);
    }

    public function consumeInvitation(User $user, string $plainPassword): void
    {
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->setStatus(UserStatusEnum::Active);
        $user->setInvitationSelector(null);
        $user->setInvitationHashedToken(null);
        $user->setInvitationExpiresAt(null);

        $this->entityManager->flush();
    }

    public function findValidInvitation(string $selector, string $token): ?User
    {
        $user = $this->userRepository->findByInvitationSelector($selector);

        if (!$user instanceof User) {
            return null;
        }

        if (!$user->isInvited()) {
            return null;
        }

        $expiresAt = $user->getInvitationExpiresAt();
        if (!$expiresAt instanceof DateTimeImmutable || $expiresAt < new DateTimeImmutable()) {
            return null;
        }

        $storedHash = $user->getInvitationHashedToken();
        if (null === $storedHash) {
            return null;
        }

        if (!hash_equals($storedHash, hash('sha256', $token))) {
            return null;
        }

        return $user;
    }

    private function prepareInvitationToken(User $user): string
    {
        $selector = bin2hex(random_bytes(10));
        $plainToken = bin2hex(random_bytes(32));

        $user->setInvitationSelector($selector);
        $user->setInvitationHashedToken(hash('sha256', $plainToken));
        $user->setInvitationExpiresAt(new DateTimeImmutable('+48 hours'));
        $user->setInvitedAt(new DateTimeImmutable());

        return $plainToken;
    }

    public function canActOn(User $actor, User $target): bool
    {
        return UserRoleEnum::highestPriorityForRoles($actor->getRoles())
            >= UserRoleEnum::highestPriorityForRoles($target->getRoles());
    }

    /**
     * Instantiates the concrete User entity. Override in a subclass to return
     * `App\Entity\User` (or any class implementing `CoreUserInterface`) -
     * `resolve_target_entities` only affects Doctrine relations, not direct
     * `new`. Used by `create()`, `register()` and `invite()`.
     */
    protected function createUser(): User
    {
        return new User();
    }
}
