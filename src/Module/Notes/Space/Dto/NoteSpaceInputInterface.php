<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Dto;

use Aurora\Module\Notes\Space\Enum\NoteSpaceAccessEnum;
use Aurora\Module\Notes\Space\Enum\NoteSpaceRoleEnum;

interface NoteSpaceInputInterface
{
    public function getName(): ?string;

    public function getColor(): ?string;

    /** {@see NoteSpaceAccessEnum}, en clair. */
    public function getAccess(): ?string;

    /** {@see NoteSpaceRoleEnum}, en clair. */
    public function getDefaultRole(): ?string;

    public function getPosition(): ?int;
}
