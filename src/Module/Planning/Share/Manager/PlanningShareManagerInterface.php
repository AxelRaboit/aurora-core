<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Share\Manager;

use Aurora\Module\Planning\Planning\Entity\PlanningInterface;

interface PlanningShareManagerInterface
{
    /**
     * @param array<int, bool> $wanted user id => may write
     */
    public function setShares(PlanningInterface $planning, array $wanted): void;
}
