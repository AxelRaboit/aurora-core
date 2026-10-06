<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Dto;

use Aurora\Module\Notes\Markdown\Enum\NoteAppearanceEnum;

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

    public function getVersion(): ?int;

    public function isForce(): bool;

    /** The space of a creation at the root; null for the personal space. */
    public function getSpaceId(): ?int;
}
