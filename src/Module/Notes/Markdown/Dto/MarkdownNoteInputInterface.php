<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Dto;

use Aurora\Module\Notes\Markdown\Enum\NoteAppearanceEnum;
use Aurora\Module\Notes\Markdown\Enum\NoteFontEnum;

interface MarkdownNoteInputInterface
{
    public function getFolderId(): ?int;

    public function getTitle(): ?string;

    public function getContent(): ?string;

    /** @return list<string> */
    public function getTags(): array;

    public function getPosition(): ?int;

    /** The address of the header image, at whoever hosts it. */
    public function getCoverUrl(): ?string;

    public function getCoverCreditName(): ?string;

    public function getCoverCreditUrl(): ?string;

    /** Where to crop the photo, as a percentage of its height. */
    public function getCoverPosition(): ?int;

    /** {@see NoteAppearanceEnum} */
    public function getAppearance(): ?string;

    /** The emoji, "" to remove it, null when not sent. */
    public function getIcon(): ?string;

    /** @return list<array<string, mixed>>|null null when not sent */
    public function getProperties(): ?array;

    public function getLocked(): ?bool;

    public function getFullWidth(): ?bool;

    public function getSmallText(): ?bool;

    /** {@see NoteFontEnum}, null when not sent */
    public function getFont(): ?string;

    public function getVersion(): ?int;

    public function isForce(): bool;

    /** The space of a creation at the root; null for the personal space. */
    public function getSpaceId(): ?int;
}
