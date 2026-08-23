<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Manager;

use Aurora\Module\Planning\Event\Dto\PlanningEventInputInterface;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Planning\Recurrence\RecurrenceScopeEnum;
use DateTimeImmutable;

interface PlanningEventManagerInterface
{
    public function create(PlanningEventInputInterface $input, PlanningInterface $planning): PlanningEventInterface;

    public function update(PlanningEventInterface $event, PlanningEventInputInterface $input, PlanningInterface $planning): void;

    public function move(PlanningEventInterface $event, DateTimeImmutable $startAt, DateTimeImmutable $endAt): void;

    public function updateAtScope(
        PlanningEventInterface $event,
        PlanningEventInputInterface $input,
        PlanningInterface $planning,
        RecurrenceScopeEnum $scope,
        ?DateTimeImmutable $occurrenceAt,
    ): PlanningEventInterface;

    public function moveAtScope(
        PlanningEventInterface $event,
        DateTimeImmutable $startAt,
        DateTimeImmutable $endAt,
        RecurrenceScopeEnum $scope,
        ?DateTimeImmutable $occurrenceAt,
    ): PlanningEventInterface;

    public function deleteAtScope(
        PlanningEventInterface $event,
        RecurrenceScopeEnum $scope,
        ?DateTimeImmutable $occurrenceAt,
    ): void;

    public function delete(PlanningEventInterface $event): void;
}
