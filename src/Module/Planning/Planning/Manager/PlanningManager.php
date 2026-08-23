<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Planning\Manager;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Planning\Planning\Dto\PlanningInputInterface;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PlanningManagerInterface::class)]
class PlanningManager implements PlanningManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
    ) {}

    public function create(PlanningInputInterface $input, CoreUserInterface $owner): PlanningInterface
    {
        $planning = $this->createPlanning();
        $this->applyInput($planning, $input);
        $planning->setOwner($owner);

        $this->entityManager->persist($planning);
        $this->entityManager->flush();

        $this->auditCreated($planning);

        return $planning;
    }

    public function update(PlanningInterface $planning, PlanningInputInterface $input): void
    {
        $this->applyInput($planning, $input);
        $this->entityManager->flush();

        $this->auditUpdated($planning);
    }

    /**
     * The events go with it, by the cascade the mapping declares.
     *
     * Deliberate, and the one destructive thing in this module: a calendar is
     * the container, and events with no calendar have nowhere to be drawn. The
     * screen says how many events will go before it asks.
     */
    /**
     * Publishes a feed for this calendar, or replaces the address if one exists.
     *
     * One method for both, because they are the same request: asking again is how
     * somebody revokes an address they shared too widely.
     *
     * Here rather than in the controller, per `convention_thin_controller` - a
     * mutation followed by a flush is the manager's job whatever its shape.
     */
    public function publishFeed(PlanningInterface $planning): void
    {
        $planning->publishFeed();
        $this->entityManager->flush();
    }

    public function revokeFeed(PlanningInterface $planning): void
    {
        $planning->revokeFeed();
        $this->entityManager->flush();
    }

    public function delete(PlanningInterface $planning): void
    {
        $this->auditDeleted($planning);

        $this->entityManager->remove($planning);
        $this->entityManager->flush();
    }

    protected function createPlanning(): PlanningInterface
    {
        return new Planning();
    }

    protected function applyInput(PlanningInterface $planning, PlanningInputInterface $input): void
    {
        $planning->setName($input->getName());
        $planning->setDescription($input->getDescription());
        $planning->setColourSlot($input->getColourSlot());
        $planning->setTimezone($input->getTimezone());
        $planning->setVisibility($input->getVisibility());
    }

    protected function auditCreated(PlanningInterface $planning): void
    {
        $this->auditLogger->log('planning', 'calendar.created', 'Planning', $planning->getId(), $this->auditPayload($planning));
    }

    protected function auditUpdated(PlanningInterface $planning): void
    {
        $this->auditLogger->log('planning', 'calendar.updated', 'Planning', $planning->getId(), $this->auditPayload($planning));
    }

    protected function auditDeleted(PlanningInterface $planning): void
    {
        $this->auditLogger->log('planning', 'calendar.deleted', 'Planning', $planning->getId(), $this->auditPayload($planning));
    }

    /** @return array<string, mixed> */
    protected function auditPayload(PlanningInterface $planning): array
    {
        // The event count goes in the log because deleting a calendar takes them
        // with it, and "deleted Travail" is a much smaller sentence than
        // "deleted Travail and 214 events".
        return ['name' => $planning->getName(), 'events' => $planning->getEvents()->count()];
    }
}
