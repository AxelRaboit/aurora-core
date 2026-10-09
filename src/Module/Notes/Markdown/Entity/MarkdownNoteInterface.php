<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Entity;

use Aurora\Core\Timestampable\TimestampableInterface;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Markdown\Enum\NoteAppearanceEnum;
use Aurora\Module\Notes\Markdown\Enum\NoteFontEnum;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;

interface MarkdownNoteInterface extends TimestampableInterface
{
    public function getId(): ?int;

    /** The author; null when their account has been deleted. */
    public function getUser(): ?CoreUserInterface;

    public function setUser(?CoreUserInterface $user): static;

    public function getFolder(): ?NoteFolderInterface;

    public function setFolder(?NoteFolderInterface $folder): static;

    public function getTitle(): ?string;

    public function setTitle(?string $title): static;

    public function getContent(): ?string;

    public function setContent(?string $content): static;

    /** @return list<string> */
    /** The address of the header image, at whoever hosts it. */
    public function getCoverUrl(): ?string;

    public function setCoverUrl(?string $coverUrl): static;

    public function getCoverCreditName(): ?string;

    public function setCoverCreditName(?string $name): static;

    public function getCoverCreditUrl(): ?string;

    public function setCoverCreditUrl(?string $url): static;

    /** Where to crop the photo, as a percentage of its height. */
    public function getCoverPosition(): int;

    public function setCoverPosition(int $percent): static;

    public function getAppearance(): NoteAppearanceEnum;

    public function setAppearance(NoteAppearanceEnum $appearance): static;

    public function getIcon(): ?string;

    public function setIcon(?string $icon): static;

    /** @return list<array{key: string, type: string, value: bool|float|int|string|null}> */
    public function getProperties(): array;

    /** @param array<mixed> $properties any list, normalised on the way in */
    public function setProperties(array $properties): static;

    public function isLocked(): bool;

    public function setLocked(bool $locked): static;

    public function isFullWidth(): bool;

    public function setFullWidth(bool $fullWidth): static;

    public function isSmallText(): bool;

    public function setSmallText(bool $smallText): static;

    public function getFont(): NoteFontEnum;

    public function setFont(NoteFontEnum $font): static;

    public function getTags(): array;

    /** @param list<string> $tags */
    public function setTags(array $tags): static;

    public function getPosition(): int;

    public function setPosition(int $position): static;

    public function isTemplate(): bool;

    public function setTemplate(bool $template): static;

    public function getDeletedAt(): ?DateTimeImmutable;

    public function setDeletedAt(?DateTimeImmutable $deletedAt): static;

    /** Whether this note sits in the trash rather than in the tree. */
    public function isTrashed(): bool;

    /** The folder whose deletion took this note down, if any. */
    public function getTrashedWithFolderId(): ?int;

    public function setTrashedWithFolderId(?int $trashedWithFolderId): static;

    /** Moves forward on each write of the content: a save started from an outdated version is refused. */
    public function getVersion(): int;

    public function bumpVersion(): void;

    /** The space where the row lives: it is what says who reads it and who writes it. */
    public function getSpace(): NoteSpaceInterface;

    public function setSpace(NoteSpaceInterface $space): static;

    /** The Craft document the note is a copy of, when it comes from one. */
    public function getCraftDocumentId(): ?string;

    public function setCraftDocumentId(?string $craftDocumentId): static;
}
