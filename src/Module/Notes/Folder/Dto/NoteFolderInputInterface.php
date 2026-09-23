<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Folder\Dto;

interface NoteFolderInputInterface
{
    public function getName(): ?string;

    /** `#rrggbb`, ou null pour un dossier sans couleur. */
    public function getColor(): ?string;

    public function getParentId(): ?int;

    public function getPosition(): ?int;
}
