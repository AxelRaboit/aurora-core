<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Entity;

use Aurora\Core\Locale\Enum\LocaleEnum;
use Aurora\Module\Platform\User\Enum\UserStatusEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Aurora's domain contract for the User entity. Extends Symfony's security
 * interfaces so security/authentication continues to type-hint against the
 * standard contract while application code can reference Aurora-specific
 * methods through this interface.
 */
interface CoreUserInterface extends UserInterface, PasswordAuthenticatedUserInterface
{
    public function getId(): ?int;

    public function getReference(): ?string;

    public function setReference(?string $reference): static;

    public function getEmail(): string;

    public function setEmail(string $email): static;

    public function getName(): string;

    public function setName(string $name): static;

    /** @param list<string> $roles */
    public function setRoles(array $roles): static;

    public function setPassword(string $password): static;

    public function getLocale(): LocaleEnum;

    public function setLocale(LocaleEnum $locale): static;

    public function getStatus(): UserStatusEnum;

    public function setStatus(UserStatusEnum $status): static;

    public function isActive(): bool;

    public function isInvited(): bool;

    public function getInvitationSelector(): ?string;

    public function setInvitationSelector(?string $selector): static;

    public function getInvitationHashedToken(): ?string;

    public function setInvitationHashedToken(?string $hashedToken): static;

    public function getInvitationExpiresAt(): ?DateTimeImmutable;

    public function setInvitationExpiresAt(?DateTimeImmutable $expiresAt): static;

    public function getInvitedAt(): ?DateTimeImmutable;

    public function setInvitedAt(?DateTimeImmutable $invitedAt): static;

    public function getEmailVerificationToken(): ?string;

    public function setEmailVerificationToken(?string $token): static;

    public function getEmailVerificationExpiresAt(): ?DateTimeImmutable;

    public function setEmailVerificationExpiresAt(?DateTimeImmutable $expiresAt): static;

    public function getType(): UserTypeEnum;

    public function setType(UserTypeEnum $type): static;

    public function isAdmin(): bool;

    public function isFrontUser(): bool;

    public function getProfilePhotoPath(): ?string;

    public function setProfilePhotoPath(?string $profilePhotoPath): static;

    public function getMoodMessage(): ?string;

    public function setMoodMessage(?string $moodMessage): static;

    public function getManager(): ?CoreUserInterface;

    public function setManager(?CoreUserInterface $manager): static;

    /** @return Collection<int, CoreUserInterface> */
    public function getSubordinates(): Collection;

    /** @return list<string> */
    public function getPrivileges(): array;

    /** @param list<string> $privileges */
    public function setPrivileges(array $privileges): static;

    public function hasPrivilege(string $privilege): bool;

    /** @return list<string> ModuleParameterEnum values masked for this user */
    public function getDisabledModules(): array;

    /** @param list<string> $disabledModules */
    public function setDisabledModules(array $disabledModules): static;

    /** @return list<string> NavSection ids hidden by this user from their own sidemenu */
    public function getHiddenNavSections(): array;

    /** @param list<string> $hiddenNavSections */
    public function setHiddenNavSections(array $hiddenNavSections): static;

    /** @return list<string> NavItem route names hidden by this user from their own sidemenu */
    public function getHiddenNavItems(): array;

    /** @param list<string> $hiddenNavItems */
    public function setHiddenNavItems(array $hiddenNavItems): static;

    /** @return array<string, string> Per-section colour overrides (sectionId → palette name) */
    public function getNavSectionColors(): array;

    /** @param array<string, string> $navSectionColors */
    public function setNavSectionColors(array $navSectionColors): static;

    public function getCreatedAt(): DateTimeImmutable;
}
