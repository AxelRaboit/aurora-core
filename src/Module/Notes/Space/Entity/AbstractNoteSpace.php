<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Entity;

use Aurora\Core\Encryption\Doctrine\EncryptedTextType;
use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Notes\Space\Enum\NoteSpaceAccessEnum;
use Aurora\Module\Notes\Space\Enum\NoteSpaceRoleEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un espace de notes : un endroit où ranger, et une réponse à « qui le voit ».
 *
 * **Deux réglages indépendants.** L'accès dans le back-office - le
 * propriétaire seul, des personnes inscrites, ou tout le back-office - et la
 * publication sur le web, en lecture, sans connexion. Une documentation
 * publique qu'on écrit seul est un cas courant, et un choix unique parmi
 * quatre ne savait pas le dire.
 *
 * **L'espace personnel** est un espace comme un autre, marqué par
 * `personalUser` : un par personne, créé tout seul, jamais ouvert aux autres
 * ni supprimé. Il part avec le compte, par la clé en cascade ; un espace
 * partagé, lui, survit au départ de son propriétaire, dont la clé passe à
 * null - le contenu d'une équipe n'appartient pas qu'à celui qui l'a créé.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractNoteSpace implements NoteSpaceInterface
{
    use TimestampableTrait;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(unique: true, nullable: true, onDelete: 'CASCADE')]
    protected ?CoreUserInterface $personalUser = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?CoreUserInterface $owner = null;

    /** Chiffré comme le nom d'un dossier : un nom d'espace dit déjà quelque chose. */
    #[ORM\Column(type: EncryptedTextType::NAME, nullable: true)]
    protected ?string $name = null;

    #[ORM\Column(length: 7, nullable: true)]
    protected ?string $color = null;

    #[ORM\Column(length: 16, enumType: NoteSpaceAccessEnum::class, options: ['default' => 'private'])]
    protected NoteSpaceAccessEnum $access = NoteSpaceAccessEnum::Private;

    #[ORM\Column(length: 16, enumType: NoteSpaceRoleEnum::class, options: ['default' => 'reader'])]
    protected NoteSpaceRoleEnum $defaultRole = NoteSpaceRoleEnum::Reader;

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $publishedAt = null;

    /** L'adresse publique ; en clair, puisqu'elle est faite pour être lue. */
    #[ORM\Column(length: 120, unique: true, nullable: true)]
    protected ?string $slug = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    protected bool $indexable = false;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 0])]
    protected int $position = 0;

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $deletedAt = null;

    public function getPersonalUser(): ?CoreUserInterface
    {
        return $this->personalUser;
    }

    public function setPersonalUser(?CoreUserInterface $user): static
    {
        $this->personalUser = $user;

        return $this;
    }

    public function isPersonal(): bool
    {
        return $this->personalUser instanceof CoreUserInterface;
    }

    public function getOwner(): ?CoreUserInterface
    {
        return $this->owner;
    }

    public function setOwner(?CoreUserInterface $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getAccess(): NoteSpaceAccessEnum
    {
        return $this->access;
    }

    public function setAccess(NoteSpaceAccessEnum $access): static
    {
        $this->access = $access;

        return $this;
    }

    public function getDefaultRole(): NoteSpaceRoleEnum
    {
        return $this->defaultRole;
    }

    public function setDefaultRole(NoteSpaceRoleEnum $role): static
    {
        $this->defaultRole = $role;

        return $this;
    }

    public function getPublishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?DateTimeImmutable $at): static
    {
        $this->publishedAt = $at;

        return $this;
    }

    public function isPublished(): bool
    {
        return $this->publishedAt instanceof DateTimeImmutable && null !== $this->slug && !$this->deletedAt instanceof DateTimeImmutable;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function isIndexable(): bool
    {
        return $this->indexable;
    }

    public function setIndexable(bool $indexable): static
    {
        $this->indexable = $indexable;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?DateTimeImmutable $at): static
    {
        $this->deletedAt = $at;

        return $this;
    }
}
