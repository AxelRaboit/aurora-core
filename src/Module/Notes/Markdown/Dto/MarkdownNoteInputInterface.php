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

    /** L'adresse de l'image d'entête, chez celui qui l'héberge. */
    public function getCoverUrl(): ?string;

    public function getCoverCreditName(): ?string;

    public function getCoverCreditUrl(): ?string;

    /** Où couper la photo, en pourcentage de sa hauteur. */
    public function getCoverPosition(): ?int;

    /** {@see NoteAppearanceEnum} */
    public function getAppearance(): ?string;
}
