<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Planning\Manager;

use Aurora\Module\Planning\Planning\Dto\PlanningInputInterface;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;

interface PlanningManagerInterface
{
    public function create(PlanningInputInterface $input, CoreUserInterface $owner): PlanningInterface;

    public function update(PlanningInterface $planning, PlanningInputInterface $input): void;

    public function delete(PlanningInterface $planning): void;
}
