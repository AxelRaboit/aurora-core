<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Entity;

use Aurora\Core\Locale\Enum\LocaleEnum;
use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserStatusEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractUser implements CoreUserInterface
{
    use TimestampableTrait;

    public const int MOOD_MESSAGE_MAX_LENGTH = 160;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    #[Groups(['user:read'])]
    protected ?string $reference = null;

    #[ORM\Column(length: 180)]
    #[Groups(['user:read'])]
    protected string $email;

    #[ORM\Column(length: 100)]
    #[Groups(['user:read'])]
    protected string $name;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    #[Groups(['user:read'])]
    protected array $roles = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    #[Groups(['user:read'])]
    protected array $privileges = [];

    /** @var list<string> ModuleParameterEnum values masked for this user (admin/dev-managed). */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    #[Groups(['user:read'])]
    protected array $disabledModules = [];

    /** @var list<string> NavSection stable ids hidden from this user's sidemenu (user-managed). */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    #[Groups(['user:read'])]
    protected array $hiddenNavSections = [];

    /** @var list<string> NavItem stable route names hidden from this user's sidemenu (user-managed). */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    #[Groups(['user:read'])]
    protected array $hiddenNavItems = [];

    /**
     * Per-section colour overrides for the sidemenu - `{sectionId: colorName}`
     * (e.g. `{"ged": "emerald", "configuration": "rose"}`). Unknown
     * sections fall back to the default palette defined in
     * `useSidemenuSectionTheme`. User-managed via /backend/profile/sidemenu.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    #[Groups(['user:read'])]
    protected array $navSectionColors = [];

    /**
     * Whether this user keeps the sidemenu collapsed.
     *
     * Beside the other sidemenu preferences rather than in the browser, which
     * is where it used to live: hiding a section was remembered per account
     * and collapsing the whole menu was remembered per machine, for one
     * object. It also lets the layout render the collapsed class itself, so
     * the menu no longer starts expanded and snaps shut once a script has run.
     *
     * The *width* stays in the browser: a number of pixels describes the
     * screen it was dragged on, and carrying 420 from a 27-inch monitor to a
     * laptop is worse than forgetting it.
     */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['user:read'])]
    protected bool $sidemenuCollapsed = false;

    /**
     * Whether this user wants each menu item's description under its label.
     *
     * The descriptions exist either way - they are the tooltips. This turns them
     * into standing text. Beside the other sidemenu preferences for the reason
     * given above: one object, one place.
     *
     * **On by default.** A menu that explains itself is the better first
     * impression, and someone who does not want the second line turns it off
     * once and is never asked again - whereas a description nobody knows exists
     * is a tooltip nobody hovers.
     */
    #[ORM\Column(options: ['default' => true])]
    #[Groups(['user:read'])]
    protected bool $sidemenuShowDescriptions = true;

    #[ORM\Column]
    protected string $password;

    #[ORM\Column(length: 5, enumType: LocaleEnum::class)]
    #[Groups(['user:read'])]
    protected LocaleEnum $locale = LocaleEnum::French;

    #[ORM\Column(length: 20, enumType: UserStatusEnum::class, options: ['default' => 'active'])]
    #[Groups(['user:read'])]
    protected UserStatusEnum $status = UserStatusEnum::Active;

    #[ORM\Column(length: 20, enumType: UserTypeEnum::class, options: ['default' => 'backend'])]
    #[Groups(['user:read'])]
    protected UserTypeEnum $type = UserTypeEnum::Backend;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['user:read'])]
    protected ?string $profilePhotoPath = null;

    #[ORM\Column(length: self::MOOD_MESSAGE_MAX_LENGTH, nullable: true)]
    #[Groups(['user:read'])]
    protected ?string $moodMessage = null;

    /** @var Collection<int, CoreUserInterface> */
    protected Collection $subordinates;

    #[ORM\Column(length: 20, unique: true, nullable: true)]
    protected ?string $invitationSelector = null;

    #[ORM\Column(length: 128, nullable: true)]
    protected ?string $invitationHashedToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $invitationExpiresAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['user:read'])]
    protected ?DateTimeImmutable $invitedAt = null;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    protected ?string $emailVerificationToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $emailVerificationExpiresAt = null;

    public function __construct()
    {
        $this->subordinates = new ArrayCollection();
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = UserRoleEnum::User->value;

        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getLocale(): LocaleEnum
    {
        return $this->locale;
    }

    public function setLocale(LocaleEnum $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getStatus(): UserStatusEnum
    {
        return $this->status;
    }

    public function setStatus(UserStatusEnum $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isActive(): bool
    {
        return UserStatusEnum::Active === $this->status;
    }

    public function isInvited(): bool
    {
        return UserStatusEnum::Invited === $this->status;
    }

    public function getInvitationSelector(): ?string
    {
        return $this->invitationSelector;
    }

    public function setInvitationSelector(?string $selector): static
    {
        $this->invitationSelector = $selector;

        return $this;
    }

    public function getInvitationHashedToken(): ?string
    {
        return $this->invitationHashedToken;
    }

    public function setInvitationHashedToken(?string $hashedToken): static
    {
        $this->invitationHashedToken = $hashedToken;

        return $this;
    }

    public function getInvitationExpiresAt(): ?DateTimeImmutable
    {
        return $this->invitationExpiresAt;
    }

    public function setInvitationExpiresAt(?DateTimeImmutable $expiresAt): static
    {
        $this->invitationExpiresAt = $expiresAt;

        return $this;
    }

    public function getInvitedAt(): ?DateTimeImmutable
    {
        return $this->invitedAt;
    }

    public function setInvitedAt(?DateTimeImmutable $invitedAt): static
    {
        $this->invitedAt = $invitedAt;

        return $this;
    }

    public function getEmailVerificationToken(): ?string
    {
        return $this->emailVerificationToken;
    }

    public function setEmailVerificationToken(?string $token): static
    {
        $this->emailVerificationToken = $token;

        return $this;
    }

    public function getEmailVerificationExpiresAt(): ?DateTimeImmutable
    {
        return $this->emailVerificationExpiresAt;
    }

    public function setEmailVerificationExpiresAt(?DateTimeImmutable $expiresAt): static
    {
        $this->emailVerificationExpiresAt = $expiresAt;

        return $this;
    }

    public function getType(): UserTypeEnum
    {
        return $this->type;
    }

    public function setType(UserTypeEnum $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function isAdmin(): bool
    {
        return UserTypeEnum::Backend === $this->type;
    }

    public function isFrontUser(): bool
    {
        return UserTypeEnum::Frontend === $this->type;
    }

    public function getProfilePhotoPath(): ?string
    {
        return $this->profilePhotoPath;
    }

    public function setProfilePhotoPath(?string $profilePhotoPath): static
    {
        $this->profilePhotoPath = $profilePhotoPath;

        return $this;
    }

    public function getMoodMessage(): ?string
    {
        return $this->moodMessage;
    }

    public function setMoodMessage(?string $moodMessage): static
    {
        if (null !== $moodMessage && mb_strlen($moodMessage) > self::MOOD_MESSAGE_MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('Mood message exceeds %d characters.', self::MOOD_MESSAGE_MAX_LENGTH));
        }

        $this->moodMessage = $moodMessage;

        return $this;
    }

    public function getSubordinates(): Collection
    {
        return $this->subordinates;
    }

    public function getPrivileges(): array
    {
        return $this->privileges;
    }

    public function setPrivileges(array $privileges): static
    {
        $this->privileges = array_values(array_unique($privileges));

        return $this;
    }

    public function hasPrivilege(string $privilege): bool
    {
        return in_array($privilege, $this->privileges, true);
    }

    public function getDisabledModules(): array
    {
        return $this->disabledModules;
    }

    public function setDisabledModules(array $disabledModules): static
    {
        $this->disabledModules = array_values(array_unique($disabledModules));

        return $this;
    }

    public function getHiddenNavSections(): array
    {
        return $this->hiddenNavSections;
    }

    public function isSidemenuCollapsed(): bool
    {
        return $this->sidemenuCollapsed;
    }

    public function setSidemenuCollapsed(bool $sidemenuCollapsed): static
    {
        $this->sidemenuCollapsed = $sidemenuCollapsed;

        return $this;
    }

    public function isSidemenuShowDescriptions(): bool
    {
        return $this->sidemenuShowDescriptions;
    }

    public function setSidemenuShowDescriptions(bool $sidemenuShowDescriptions): static
    {
        $this->sidemenuShowDescriptions = $sidemenuShowDescriptions;

        return $this;
    }

    public function setHiddenNavSections(array $hiddenNavSections): static
    {
        $this->hiddenNavSections = array_values(array_unique($hiddenNavSections));

        return $this;
    }

    public function getHiddenNavItems(): array
    {
        return $this->hiddenNavItems;
    }

    public function setHiddenNavItems(array $hiddenNavItems): static
    {
        $this->hiddenNavItems = array_values(array_unique($hiddenNavItems));

        return $this;
    }

    /** @return array<string, string> */
    public function getNavSectionColors(): array
    {
        return $this->navSectionColors;
    }

    /** @param array<string, string> $navSectionColors */
    public function setNavSectionColors(array $navSectionColors): static
    {
        $this->navSectionColors = $navSectionColors;

        return $this;
    }

    public function eraseCredentials(): void {}
}
