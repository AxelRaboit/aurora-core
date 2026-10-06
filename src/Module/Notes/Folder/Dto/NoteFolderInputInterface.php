<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Folder\Dto;

interface NoteFolderInputInterface
{
    public function getName(): ?string;

    /** `#rrggbb`, or null for a folder without a colour. */
    public function getColor(): ?string;

    public function getParentId(): ?int;

    public function getPosition(): ?int;

    /** The space of a creation at the root; null for one's personal space. */
    public function getSpaceId(): ?int;
}
