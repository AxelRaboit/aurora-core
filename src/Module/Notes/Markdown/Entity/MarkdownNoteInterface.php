<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Entity;

use Aurora\Core\Timestampable\TimestampableInterface;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Markdown\Enum\NoteAppearanceEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;

interface MarkdownNoteInterface extends TimestampableInterface
{
    public function getId(): ?int;

    public function getUser(): CoreUserInterface;

    public function setUser(CoreUserInterface $user): static;

    public function getFolder(): ?NoteFolderInterface;

    public function setFolder(?NoteFolderInterface $folder): static;

    public function getTitle(): ?string;

    public function setTitle(?string $title): static;

    public function getContent(): ?string;

    public function setContent(?string $content): static;

    /** @return list<string> */
    /** L'adresse de l'image d'entête, chez celui qui l'héberge. */
    public function getCoverUrl(): ?string;

    public function setCoverUrl(?string $coverUrl): static;

    public function getCoverCreditName(): ?string;

    public function setCoverCreditName(?string $name): static;

    public function getCoverCreditUrl(): ?string;

    public function setCoverCreditUrl(?string $url): static;

    /** Où couper la photo, en pourcentage de sa hauteur. */
    public function getCoverPosition(): int;

    public function setCoverPosition(int $percent): static;

    public function getAppearance(): NoteAppearanceEnum;

    public function setAppearance(NoteAppearanceEnum $appearance): static;

    public function getTags(): array;

    /** @param list<string> $tags */
    public function setTags(array $tags): static;

    public function getPosition(): int;

    public function setPosition(int $position): static;

    /** When the note was pinned, null when it is not. */
    /** Depuis quand c'est lisible par les autres, ou jamais. */
    public function getSharedAt(): ?DateTimeImmutable;

    public function setSharedAt(?DateTimeImmutable $sharedAt): static;

    public function getFavoritedAt(): ?DateTimeImmutable;

    public function setFavoritedAt(?DateTimeImmutable $favoritedAt): static;

    public function getDeletedAt(): ?DateTimeImmutable;

    public function setDeletedAt(?DateTimeImmutable $deletedAt): static;

    /** Whether this note sits in the trash rather than in the tree. */
    public function isTrashed(): bool;

    /** The folder whose deletion took this note down, if any. */
    public function getTrashedWithFolderId(): ?int;

    public function setTrashedWithFolderId(?int $trashedWithFolderId): static;
}
