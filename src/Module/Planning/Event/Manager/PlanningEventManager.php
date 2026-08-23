<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Manager;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Planning\Event\Dto\PlanningEventInputInterface;
use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PlanningEventManagerInterface::class)]
class PlanningEventManager implements PlanningEventManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
    ) {}

    public function create(PlanningEventInputInterface $input, PlanningInterface $planning): PlanningEventInterface
    {
        $event = $this->createEvent();
        $event->setPlanning($planning);
        $this->applyInput($event, $input);

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        $this->auditCreated($event);

        return $event;
    }

    /**
     * The calendar is passed in rather than read off the input, because moving an
     * event between calendars is the same gesture as editing it: the form has one
     * calendar picker and the controller has already resolved it to an entity the
     * user may write to.
     */
    public function update(PlanningEventInterface $event, PlanningEventInputInterface $input, PlanningInterface $planning): void
    {
        $this->refuseIfFromModule($event);

        $event->setPlanning($planning);
        $this->applyInput($event, $input);
        $this->entityManager->flush();

        $this->auditUpdated($event);
    }

    public function delete(PlanningEventInterface $event): void
    {
        $this->refuseIfFromModule($event);

        $this->auditDeleted($event);

        $this->entityManager->remove($event);
        $this->entityManager->flush();
    }

    protected function createEvent(): PlanningEventInterface
    {
        return new PlanningEvent();
    }

    protected function applyInput(PlanningEventInterface $event, PlanningEventInputInterface $input): void
    {
        $event->setTitle($input->getTitle());
        $event->setDescription($input->getDescription());
        $event->setLocation($input->getLocation());
        $event->setAllDay($input->isAllDay());
        $event->setStatus($input->getStatus());
        // Last, and in one call: the entity refuses an end before a start, so
        // the two have to arrive together and after nothing else can throw.
        $event->setSpan($input->getStartAt(), $input->getEndAt());
    }

    /**
     * An event a module pushed is not ours to change.
     *
     * Refused in the manager and not only hidden in the screen: the screen
     * already leaves out Edit and Delete, but a request can arrive without one,
     * and the next writer of a controller should not have to remember this.
     * Editing it would also be pointless - the subscriber that pushed it
     * rewrites it the next time its source changes.
     */
    protected function refuseIfFromModule(PlanningEventInterface $event): void
    {
        if ($event->isFromModule()) {
            throw new RuntimeException('A planning event owned by a module cannot be edited from the calendar.');
        }
    }

    protected function auditCreated(PlanningEventInterface $event): void
    {
        $this->auditLogger->log('planning', 'event.created', 'PlanningEvent', $event->getId(), $this->auditPayload($event));
    }

    protected function auditUpdated(PlanningEventInterface $event): void
    {
        $this->auditLogger->log('planning', 'event.updated', 'PlanningEvent', $event->getId(), $this->auditPayload($event));
    }

    protected function auditDeleted(PlanningEventInterface $event): void
    {
        $this->auditLogger->log('planning', 'event.deleted', 'PlanningEvent', $event->getId(), $this->auditPayload($event));
    }

    /** @return array<string, mixed> */
    protected function auditPayload(PlanningEventInterface $event): array
    {
        return [
            'name' => $event->getTitle(),
            'startAt' => $event->getStartAt()->format(DATE_ATOM),
            'calendar' => $event->getPlanning()->getName(),
        ];
    }
}
