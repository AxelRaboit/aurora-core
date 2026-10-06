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

    /** La personne dont c'est l'espace personnel ; null pour un espace partagé. */
    public function getPersonalUser(): ?CoreUserInterface;

    public function setPersonalUser(?CoreUserInterface $user): static;

    public function isPersonal(): bool;

    /** Le propriétaire ; null quand son compte a été supprimé. */
    public function getOwner(): ?CoreUserInterface;

    public function setOwner(?CoreUserInterface $owner): static;

    public function getName(): ?string;

    public function setName(?string $name): static;

    public function getColor(): ?string;

    public function setColor(?string $color): static;

    public function getAccess(): NoteSpaceAccessEnum;

    public function setAccess(NoteSpaceAccessEnum $access): static;

    /** Le rôle de toute personne du back-office, quand l'accès est ouvert à tous. */
    public function getDefaultRole(): NoteSpaceRoleEnum;

    public function setDefaultRole(NoteSpaceRoleEnum $role): static;

    public function getPublishedAt(): ?DateTimeImmutable;

    public function setPublishedAt(?DateTimeImmutable $at): static;

    public function isPublished(): bool;

    public function getSlug(): ?string;

    public function setSlug(?string $slug): static;

    /** Visible dans les moteurs de recherche, une fois publié. Non par défaut. */
    public function isIndexable(): bool;

    public function setIndexable(bool $indexable): static;

    public function getPosition(): int;

    public function setPosition(int $position): static;

    public function getDeletedAt(): ?DateTimeImmutable;

    public function setDeletedAt(?DateTimeImmutable $at): static;

    /** Ce qui règle l'espace à la place de ses gestionnaires ; null pour un espace ordinaire. */
    public function getManagedBy(): ?string;

    public function setManagedBy(?string $managedBy): static;

    /** Son nom, son accès et ses membres viennent d'ailleurs : l'écran des notes ne les règle pas. */
    public function isManaged(): bool;

    /** @return Collection<int, NoteSpaceMemberInterface> */
    public function getMembers(): Collection;
}
