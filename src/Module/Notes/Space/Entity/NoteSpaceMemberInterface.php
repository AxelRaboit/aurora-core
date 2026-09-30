<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Entity;

use Aurora\Module\Notes\Space\Enum\NoteSpaceRoleEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;

interface NoteSpaceMemberInterface
{
    public function getId(): ?int;

    public function getSpace(): NoteSpaceInterface;

    public function setSpace(NoteSpaceInterface $space): static;

    public function getUser(): CoreUserInterface;

    public function setUser(CoreUserInterface $user): static;

    public function getRole(): NoteSpaceRoleEnum;

    public function setRole(NoteSpaceRoleEnum $role): static;
}
