<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Reminder\Manager;

use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Planning\Reminder\Dto\PlanningReminderInputInterface;
use Aurora\Module\Planning\Reminder\Entity\PlanningReminderInterface;

interface PlanningReminderManagerInterface
{
    public function create(PlanningReminderInputInterface $input, PlanningInterface $planning): PlanningReminderInterface;

    public function update(PlanningReminderInterface $reminder, PlanningReminderInputInterface $input, PlanningInterface $planning): void;

    public function delete(PlanningReminderInterface $reminder): void;

    /** @return bool the state it ended up in */
    public function toggle(PlanningReminderInterface $reminder): bool;
}
