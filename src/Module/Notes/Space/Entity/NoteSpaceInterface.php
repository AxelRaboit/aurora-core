<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Entity;

use Aurora\Core\Timestampable\TimestampableInterface;
use Aurora\Module\Notes\Space\Enum\NoteSpaceAccessEnum;
use Aurora\Module\Notes\Space\Enum\NoteSpaceRoleEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;

interface NoteSpaceInterface extends TimestampableInterface
{
    public function getId(): ?int;

    /** The person whose personal space this is; null for a shared space. */
    public function getPersonalUser(): ?CoreUserInterface;

    public function setPersonalUser(?CoreUserInterface $user): static;

    public function isPersonal(): bool;

    /** The owner; null when their account has been deleted. */
    public function getOwner(): ?CoreUserInterface;

    public function setOwner(?CoreUserInterface $owner): static;

    public function getName(): ?string;

    public function setName(?string $name): static;

    public function getColor(): ?string;

    public function setColor(?string $color): static;

    public function getAccess(): NoteSpaceAccessEnum;

    public function setAccess(NoteSpaceAccessEnum $access): static;

    /** The role of anybody in the back office, when access is open to all. */
    public function getDefaultRole(): NoteSpaceRoleEnum;

    public function setDefaultRole(NoteSpaceRoleEnum $role): static;

    /** Whether this space allows co-editing, and is allowed to - never a personal one. */
    public function allowsCoediting(): bool;

    public function setCoediting(bool $coediting): static;

    public function getPublishedAt(): ?DateTimeImmutable;

    public function setPublishedAt(?DateTimeImmutable $at): static;

    public function isPublished(): bool;

    public function getSlug(): ?string;

    public function setSlug(?string $slug): static;

    /** Visible in search engines, once published. No by default. */
    public function isIndexable(): bool;

    public function setIndexable(bool $indexable): static;

    public function getPosition(): int;

    public function setPosition(int $position): static;

    public function getDeletedAt(): ?DateTimeImmutable;

    public function setDeletedAt(?DateTimeImmutable $at): static;

    /** What configures the space instead of its managers; null for an ordinary space. */
    public function getManagedBy(): ?string;

    public function setManagedBy(?string $managedBy): static;

    /** Its name, access and members come from elsewhere: the notes screen does not configure them. */
    public function isManaged(): bool;

    /** @return Collection<int, NoteSpaceMemberInterface> */
    public function getMembers(): Collection;
}
