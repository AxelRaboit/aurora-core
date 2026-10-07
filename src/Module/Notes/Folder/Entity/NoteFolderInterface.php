<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Folder\Entity;

use Aurora\Core\Timestampable\TimestampableInterface;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;

/**
 * A shelf, not a page.
 *
 * The distinction this interface exists to make: a folder has a name and
 * holds things, a note has a body and is read. Before it, a note with
 * children stood in for both, and every screen had to guess which one it was
 * looking at.
 */
interface NoteFolderInterface extends TimestampableInterface
{
    public function getId(): ?int;

    /** The author; null when their account has been deleted. */
    public function getUser(): ?CoreUserInterface;

    public function setUser(?CoreUserInterface $user): static;

    public function getParent(): ?self;

    public function setParent(?self $parent): static;

    /** @return Collection<int, NoteFolderInterface> */
    public function getChildren(): Collection;

    public function getName(): ?string;

    public function setName(?string $name): static;

    /**
     * The folder's colour, `#rrggbb`, or null if it has none.
     *
     * In clear, unlike the name: a colour says nothing about what is inside,
     * and that is what allows sorting and counting it in SQL the day a screen
     * asks for it.
     */
    public function getColor(): ?string;

    public function setColor(?string $color): static;

    public function getPosition(): int;

    public function setPosition(int $position): static;

    public function getDeletedAt(): ?DateTimeImmutable;

    public function setDeletedAt(?DateTimeImmutable $deletedAt): static;

    /** Whether this folder sits in the trash rather than in the tree. */
    public function isTrashed(): bool;

    /** The folder whose deletion took this one down, if any. */
    public function getTrashedWithFolderId(): ?int;

    public function setTrashedWithFolderId(?int $trashedWithFolderId): static;

    /** The space the row lives in: it says who reads it and who writes it. */
    public function getSpace(): NoteSpaceInterface;

    public function setSpace(NoteSpaceInterface $space): static;
}
