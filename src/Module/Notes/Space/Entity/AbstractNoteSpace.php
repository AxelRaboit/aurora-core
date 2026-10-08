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
 * A note space: a place to file things, and an answer to "who sees it".
 *
 * **Two independent settings.** Access in the back office - the owner alone,
 * members, or the whole back office - and publication on the web, read-only,
 * without logging in. Public documentation written alone is a common case,
 * and a single choice among four could not express it.
 *
 * **The personal space** is a space like any other, marked by
 * `personalUser`: one per person, created automatically, never opened to
 * others nor deleted. It leaves with the account, through the cascading key;
 * a shared space survives its owner's departure, whose key becomes null - a
 * team's content does not belong only to whoever created it.
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

    /** Encrypted like a folder's name: a space's name already says something. */
    #[ORM\Column(type: EncryptedTextType::NAME, nullable: true)]
    protected ?string $name = null;

    #[ORM\Column(length: 7, nullable: true)]
    protected ?string $color = null;

    #[ORM\Column(length: 16, enumType: NoteSpaceAccessEnum::class, options: ['default' => 'private'])]
    protected NoteSpaceAccessEnum $access = NoteSpaceAccessEnum::Private;

    #[ORM\Column(length: 16, enumType: NoteSpaceRoleEnum::class, options: ['default' => 'reader'])]
    protected NoteSpaceRoleEnum $defaultRole = NoteSpaceRoleEnum::Reader;

    /**
     * Whether the notes of this space may be written by several people at
     * once, letter by letter.
     *
     * **Off by default, and never available on a personal space.** A
     * co-editing session hands the note's text to a second place while it is
     * open - the other browsers - and a private notebook does not change the
     * promise it made. A space, on the other hand, already says who sees what
     * it holds, so this is one more thing it says.
     *
     * Set once by whoever manages the space rather than per note: two places
     * to consult to know whether a note is co-editable is the kind of setting
     * nobody remembers the precedence of six months later.
     */
    #[ORM\Column(options: ['default' => false])]
    protected bool $coediting = false;

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $publishedAt = null;

    /** The public address; in clear, since it is meant to be read. */
    #[ORM\Column(length: 120, unique: true, nullable: true)]
    protected ?string $slug = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    protected bool $indexable = false;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 0])]
    protected int $position = 0;

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $deletedAt = null;

    /**
     * What configures the space instead of its managers, when something
     * does: `studio.customer_space` for the note space of a client space.
     *
     * **A marker and not a relation.** The Notes module does not know
     * Studio: Studio points at the note space, and the note space only says
     * "my name, my access and my members come from elsewhere". The notes
     * screen therefore refuses to configure them (rename, open, add members,
     * publish, remove), and that is the only consequence. People write, file
     * and share a note through a link there as in any other space.
     *
     * Reset to null when whatever configured it disappears: the space becomes
     * an ordinary shared space again, without an owner, which the
     * administrators take over.
     */
    #[ORM\Column(length: 64, nullable: true)]
    protected ?string $managedBy = null;

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

    /**
     * Whether this space allows co-editing **and** is allowed to.
     *
     * The personal space is refused here rather than at the edge, so that no
     * screen and no route has to remember it: a notebook that belongs to one
     * person has nobody to co-edit with anyway, and the promise it makes about
     * who can read it is the reason it is not offered.
     */
    public function allowsCoediting(): bool
    {
        return $this->coediting && !$this->isPersonal();
    }

    public function setCoediting(bool $coediting): static
    {
        $this->coediting = $coediting;

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

    public function getManagedBy(): ?string
    {
        return $this->managedBy;
    }

    public function setManagedBy(?string $managedBy): static
    {
        $this->managedBy = $managedBy;

        return $this;
    }

    public function isManaged(): bool
    {
        return null !== $this->managedBy;
    }
}
