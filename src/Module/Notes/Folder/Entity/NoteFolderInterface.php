<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Folder\Entity;

use Aurora\Core\Timestampable\TimestampableInterface;
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

    public function getUser(): CoreUserInterface;

    public function setUser(CoreUserInterface $user): static;

    public function getParent(): ?self;

    public function setParent(?self $parent): static;

    /** @return Collection<int, NoteFolderInterface> */
    public function getChildren(): Collection;

    public function getName(): ?string;

    public function setName(?string $name): static;

    /**
     * La couleur du dossier, `#rrggbb`, ou null s'il n'en porte pas.
     *
     * En clair, contrairement au nom : une couleur ne dit rien de ce qu'il y
     * a dedans, et c'est ce qui permet de la trier et de la compter en SQL
     * le jour où un écran le demandera.
     */
    public function getColor(): ?string;

    public function setColor(?string $color): static;

    public function getPosition(): int;

    public function setPosition(int $position): static;

    /** When the folder was pinned, null when it is not. */
    public function getFavoritedAt(): ?DateTimeImmutable;

    public function setFavoritedAt(?DateTimeImmutable $favoritedAt): static;

    public function getDeletedAt(): ?DateTimeImmutable;

    public function setDeletedAt(?DateTimeImmutable $deletedAt): static;

    /** Whether this folder sits in the trash rather than in the tree. */
    public function isTrashed(): bool;

    /** The folder whose deletion took this one down, if any. */
    public function getTrashedWithFolderId(): ?int;

    public function setTrashedWithFolderId(?int $trashedWithFolderId): static;
}
