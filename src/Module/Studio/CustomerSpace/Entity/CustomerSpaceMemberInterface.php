<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Entity;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;

interface CustomerSpaceMemberInterface
{
    public function getId(): ?int;

    public function getSpace(): CustomerSpaceInterface;

    public function setSpace(CustomerSpaceInterface $space): static;

    public function getUser(): CoreUserInterface;

    public function setUser(CoreUserInterface $user): static;

    public function getRole(): CustomerSpaceMemberRoleEnum;

    public function setRole(CustomerSpaceMemberRoleEnum $role): static;
}
