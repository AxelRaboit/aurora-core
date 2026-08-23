<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Share\Entity;

use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;

interface PlanningShareInterface
{
    public function getId(): ?int;

    public function getPlanning(): PlanningInterface;

    public function setPlanning(PlanningInterface $planning): static;

    public function getUser(): CoreUserInterface;

    public function setUser(CoreUserInterface $user): static;

    public function canWrite(): bool;

    public function setCanWrite(bool $canWrite): static;
}
