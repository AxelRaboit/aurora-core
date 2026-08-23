<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Attendee\Manager;

use Aurora\Module\Planning\Attendee\Enum\PlanningAttendeeStatusEnum;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;

interface PlanningAttendeeManagerInterface
{
    /**
     * @return bool false when this person was never invited
     */
    public function respond(
        PlanningEventInterface $event,
        CoreUserInterface $user,
        PlanningAttendeeStatusEnum $status,
    ): bool;
}
