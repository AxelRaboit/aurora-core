<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Reminder\Manager;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Planning\Reminder\Dto\PlanningReminderInputInterface;
use Aurora\Module\Planning\Reminder\Entity\PlanningReminder;
use Aurora\Module\Planning\Reminder\Entity\PlanningReminderInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PlanningReminderManagerInterface::class)]
class PlanningReminderManager implements PlanningReminderManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
    ) {}

    public function create(PlanningReminderInputInterface $input, PlanningInterface $planning): PlanningReminderInterface
    {
        $reminder = $this->createReminder();
        $reminder->setPlanning($planning);
        $this->applyInput($reminder, $input);

        $this->entityManager->persist($reminder);
        $this->entityManager->flush();

        $this->auditLogger->log('planning', 'reminder.created', 'PlanningReminder', $reminder->getId(), $this->auditPayload($reminder));

        return $reminder;
    }

    public function update(PlanningReminderInterface $reminder, PlanningReminderInputInterface $input, PlanningInterface $planning): void
    {
        $reminder->setPlanning($planning);
        $this->applyInput($reminder, $input);
        $this->entityManager->flush();

        $this->auditLogger->log('planning', 'reminder.updated', 'PlanningReminder', $reminder->getId(), $this->auditPayload($reminder));
    }

    public function delete(PlanningReminderInterface $reminder): void
    {
        $this->auditLogger->log('planning', 'reminder.deleted', 'PlanningReminder', $reminder->getId(), $this->auditPayload($reminder));

        $this->entityManager->remove($reminder);
        $this->entityManager->flush();
    }

    /**
     * Ticks or unticks, and says which.
     *
     * One route rather than two, because the control is one checkbox and the
     * client should not have to know which way it is about to go - it knows what
     * it drew, but the row may have changed under it in another tab, and the
     * honest answer is whatever the database now says.
     *
     * Not audited. A calendar where every tick writes a log line buries the
     * events worth reading, and the reminder itself already records when it was
     * done.
     */
    public function toggle(PlanningReminderInterface $reminder): bool
    {
        if ($reminder->isCompleted()) {
            $reminder->reopen();
        } else {
            $reminder->complete(new DateTimeImmutable());
        }

        $this->entityManager->flush();

        return $reminder->isCompleted();
    }

    protected function createReminder(): PlanningReminderInterface
    {
        return new PlanningReminder();
    }

    protected function applyInput(PlanningReminderInterface $reminder, PlanningReminderInputInterface $input): void
    {
        $reminder->setTitle($input->getTitle());
        $reminder->setNotes($input->getNotes());
        $reminder->setAllDay($input->isAllDay());
        $reminder->setChannel($input->getChannel());
        // Before the completion flag, because moving the date clears `notifiedAt`
        // and the order should be the one that reads as cause then consequence.
        $reminder->setDueAt($input->getDueAt());

        if ($input->isCompleted() && !$reminder->isCompleted()) {
            $reminder->complete(new DateTimeImmutable());
        } elseif (!$input->isCompleted() && $reminder->isCompleted()) {
            $reminder->reopen();
        }
    }

    /** @return array<string, mixed> */
    protected function auditPayload(PlanningReminderInterface $reminder): array
    {
        return [
            'name' => $reminder->getTitle(),
            'dueAt' => $reminder->getDueAt()->format(DATE_ATOM),
            'calendar' => $reminder->getPlanning()->getName(),
        ];
    }
}
