<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Manager;

use Aurora\Module\Planning\Event\Dto\PlanningEventInputInterface;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use DateTimeImmutable;

interface PlanningEventManagerInterface
{
    public function create(PlanningEventInputInterface $input, PlanningInterface $planning): PlanningEventInterface;

    public function update(PlanningEventInterface $event, PlanningEventInputInterface $input, PlanningInterface $planning): void;

    public function move(PlanningEventInterface $event, DateTimeImmutable $startAt, DateTimeImmutable $endAt): void;

    public function delete(PlanningEventInterface $event): void;
}
