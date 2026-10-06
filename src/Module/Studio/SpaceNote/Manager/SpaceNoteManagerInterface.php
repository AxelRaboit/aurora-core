<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Manager;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceNote\Dto\SpaceNoteInputInterface;
use Aurora\Module\Studio\SpaceNote\Entity\SpaceNoteInterface;

interface SpaceNoteManagerInterface
{
    public function create(CustomerSpaceInterface $space, SpaceNoteInputInterface $input): SpaceNoteInterface;

    public function update(SpaceNoteInterface $note, SpaceNoteInputInterface $input): void;

    /** Épingler ou dépingler, sans rouvrir la note. */
    public function togglePinned(SpaceNoteInterface $note): void;

    public function delete(SpaceNoteInterface $note): void;
}
