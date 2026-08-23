<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Manager;

use Aurora\Core\Notification\Manager\NotificationManagerInterface;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Planning\Attendee\Entity\PlanningEventAttendee;
use Aurora\Module\Planning\Event\Dto\PlanningEventInputInterface;
use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Entity\PlanningEventAlert;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Planning\Event\Enum\PlanningAlertChannelEnum;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Planning\Recurrence\Manager\RecurrenceEditor;
use Aurora\Module\Planning\Recurrence\RecurrenceScopeEnum;
use Aurora\Module\Planning\Time\PlanningClock;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsAlias(PlanningEventManagerInterface::class)]
class PlanningEventManager implements PlanningEventManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
        protected readonly RecurrenceEditor $recurrence,
        protected readonly UserRepository $users,
        protected readonly NotificationManagerInterface $notifications,
        protected readonly UrlGeneratorInterface $urlGenerator,
        protected readonly TranslatorInterface $translator,
    ) {}

    /**
     * People invited by the write in progress, told after it succeeds.
     *
     * Held rather than notified in place, because `NotificationManagerInterface`
     * flushes - and on a create that flush happens before the event itself is
     * persisted, so it tried to write an attendee pointing at nothing. It is also
     * the honest order: a notification about an event that failed to save is a
     * lie.
     *
     * @var list<CoreUserInterface>
     */
    private array $newlyInvited = [];

    public function create(PlanningEventInputInterface $input, PlanningInterface $planning): PlanningEventInterface
    {
        $event = $this->createEvent();
        $event->setPlanning($planning);
        $this->applyInput($event, $input);

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        $this->auditCreated($event);
        $this->notifyInvitations($event);

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
        $this->notifyInvitations($event);
    }

    /**
     * Moves an event, and nothing else.
     *
     * Its own method rather than an `update` with a partial input, because the
     * input DTO carries every field and a drag knows two: reusing it would have
     * meant the client sending back a whole event it does not hold. Its
     * serialised form is not the input's shape either - `colourSlot` comes down
     * resolved, so echoing it back would turn an event that follows its calendar
     * into one with a colour of its own.
     *
     * The alerts follow, because `setSpan` recomputes the relative ones. That is
     * the point of the drag being a span change rather than two field writes.
     */
    public function move(PlanningEventInterface $event, DateTimeImmutable $startAt, DateTimeImmutable $endAt): void
    {
        $this->refuseIfFromModule($event);

        $event->setSpan($startAt, $endAt);
        $this->entityManager->flush();

        $this->auditUpdated($event);
    }

    /**
     * Writes an edit at the scope the reader chose.
     *
     * The scope decides which row is written before anything is written, which is
     * the whole shape of this: `This` detaches an occurrence and edits that,
     * `Following` splits the series and edits the tail, `All` edits the series
     * itself. Nothing downstream has to know which of the three happened.
     *
     * `This` clears the rule on the row it made, because a detached occurrence is
     * one event - it happens once, at the date it was moved to.
     */
    public function updateAtScope(
        PlanningEventInterface $event,
        PlanningEventInputInterface $input,
        PlanningInterface $planning,
        RecurrenceScopeEnum $scope,
        ?DateTimeImmutable $occurrenceAt,
    ): PlanningEventInterface {
        $target = $this->resolveTarget($event, $scope, $occurrenceAt);

        $this->refuseIfFromModule($target);
        // Captured before the input is applied, because a split hands back a tail
        // carrying the series' rule and `applyInput` writes whatever the payload
        // says - which for an edit that never mentioned recurrence is null. Left
        // to itself, "this and following" dissolved the series it had just made.
        $ruleBeforeInput = $target->getRrule();

        $target->setPlanning($planning);
        $this->applyInput($target, $input);

        if (RecurrenceScopeEnum::This === $scope) {
            // A detached occurrence happens once, at the date it was moved to.
            $target->setRrule(null);
            $this->recurrence->refreshUntil($target);
        } elseif (RecurrenceScopeEnum::Following === $scope && null === $input->getRrule()) {
            $target->setRrule($ruleBeforeInput);
            $this->recurrence->refreshUntil($target);
        }

        $this->entityManager->flush();
        $this->auditUpdated($target);
        $this->notifyInvitations($target);

        return $target;
    }

    /**
     * Moves an event at the scope the reader chose.
     *
     * Same resolution as an edit, and deliberately so: dragging one occurrence of
     * a weekly meeting is the same decision as editing it, and answering it two
     * ways would be two chances to answer it wrong.
     */
    public function moveAtScope(
        PlanningEventInterface $event,
        DateTimeImmutable $startAt,
        DateTimeImmutable $endAt,
        RecurrenceScopeEnum $scope,
        ?DateTimeImmutable $occurrenceAt,
    ): PlanningEventInterface {
        $target = $this->resolveTarget($event, $scope, $occurrenceAt);

        $this->refuseIfFromModule($target);
        $target->setSpan($startAt, $endAt);

        if (RecurrenceScopeEnum::This === $scope) {
            $target->setRrule(null);
        }

        $this->recurrence->refreshUntil($target);
        $this->entityManager->flush();
        $this->auditUpdated($target);

        return $target;
    }

    /**
     * Deletes at the scope the reader chose.
     *
     * `This` removes one occurrence and leaves the series. `Following` stops the
     * series before it rather than deleting anything, because the occurrences
     * already past are what was agreed. `All` removes the row, and the children
     * go with it by cascade.
     */
    public function deleteAtScope(
        PlanningEventInterface $event,
        RecurrenceScopeEnum $scope,
        ?DateTimeImmutable $occurrenceAt,
    ): void {
        $this->refuseIfFromModule($event);

        if (RecurrenceScopeEnum::This === $scope && $occurrenceAt instanceof DateTimeImmutable) {
            $this->recurrence->removeOccurrence($event, $occurrenceAt);
            $this->entityManager->flush();
            $this->auditUpdated($event);

            return;
        }

        if (RecurrenceScopeEnum::Following === $scope && $occurrenceAt instanceof DateTimeImmutable) {
            $this->recurrence->endBefore($event, $occurrenceAt);

            foreach ($event->getOccurrences() as $child) {
                $at = $child->getOccurrenceAt();
                if ($at instanceof DateTimeImmutable && $at >= $occurrenceAt) {
                    $event->getOccurrences()->removeElement($child);
                    $this->entityManager->remove($child);
                }
            }

            $this->entityManager->flush();
            $this->auditUpdated($event);

            return;
        }

        $this->delete($event);
    }

    /**
     * Which row an edit at this scope should be written to.
     *
     * Returns the event untouched for a single one or for `All`. Anything else
     * needs the occurrence being pointed at, and a scope naming an occurrence
     * without saying which is a request that cannot be honoured - refused rather
     * than guessed, because guessing would write to the wrong date.
     */
    protected function resolveTarget(
        PlanningEventInterface $event,
        RecurrenceScopeEnum $scope,
        ?DateTimeImmutable $occurrenceAt,
    ): PlanningEventInterface {
        if (!$event->isRecurring() || RecurrenceScopeEnum::All === $scope || RecurrenceScopeEnum::Single === $scope) {
            return $event;
        }

        if (!$occurrenceAt instanceof DateTimeImmutable) {
            throw new RuntimeException('A scoped edit needs the occurrence it applies to.');
        }

        // Only two scopes reach here: the other two returned above. No default
        // arm, so adding a fifth scope one day is a compile error rather than a
        // silent fall-through to "edit the series".
        return match ($scope) {
            RecurrenceScopeEnum::This => $this->recurrence->detach($event, $occurrenceAt),
            RecurrenceScopeEnum::Following => $this->recurrence->split($event, $occurrenceAt),
        };
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
        $event->setColourSlot($input->getColourSlot());
        $event->setRrule($input->getRrule());

        $startAt = $input->getStartAt();
        $endAt = $input->getEndAt();

        if ($input->isAllDay()) {
            [$startAt, $endAt] = $this->snapToWholeDays($startAt, $endAt, $event->getPlanning());
        }

        // Last, and in one call: the entity refuses an end before a start, so
        // the two have to arrive together and after nothing else can throw.
        $event->setSpan($startAt, $endAt);
        $this->applyAlerts($event, $input->getAlerts());
        $this->applyAttendees($event, $input->getAttendeeIds());
        // After the span, because the end of a series is its last start plus the
        // event's length - and both just changed.
        $this->recurrence->refreshUntil($event);
    }

    /**
     * An all-day event owns whole days - in the calendar's timezone.
     *
     * Snapped here and not in the factory, because this is the first layer that
     * knows which calendar the event lands in, and "the whole day" is a question
     * only a timezone can answer. Snapped in UTC instead, a day off for a reader
     * in Paris would start at 02:00 and end at 01:59 the next morning, and the
     * month grid would draw it across two cells - the exact defect the snap
     * exists to prevent.
     *
     * Returned in UTC, because every instant in the table is UTC.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    protected function snapToWholeDays(
        DateTimeImmutable $startAt,
        DateTimeImmutable $endAt,
        PlanningInterface $planning,
    ): array {
        $utc = PlanningClock::utcZone();
        $local = PlanningClock::zone($planning);

        $start = $startAt->setTimezone($local)->setTime(0, 0);
        $end = $endAt->setTimezone($local)->setTime(23, 59, 59);

        // An end before its start would throw on setSpan, and here it only means
        // the form sent the two days the wrong way round.
        if ($end < $start) {
            $end = $start->setTime(23, 59, 59);
        }

        return [$start->setTimezone($utc), $end->setTimezone($utc)];
    }

    /**
     * Brings the event's alerts in line with what the form sent.
     *
     * Kept as a diff rather than "remove them all and add them back", and the
     * difference is visible to the reader: an alert that survives an edit keeps
     * its `sentAt`, so renaming an event at 14:05 does not resend the 14:00
     * alert. Clearing the collection would make every save a resend.
     *
     * Matched on what identifies each kind - an offset for a relative alert, the
     * moment itself for a pinned one - because those are the two things a reader
     * chose and therefore the two things that mean "this is the same alert".
     *
     * Runs after setSpan for the same reason it exists at all: a surviving
     * relative alert has just been recomputed against the new start.
     *
     * @param list<array{minutes: int|null, at: DateTimeImmutable|null, channel: PlanningAlertChannelEnum}> $alerts
     */
    protected function applyAlerts(PlanningEventInterface $event, array $alerts): void
    {
        // Keyed on what identifies an alert, which now includes the channel: "tell
        // me and email me, both thirty minutes before" is two alerts, and matching
        // on the offset alone would keep one and delete the other on every save.
        $wanted = [];
        foreach ($alerts as $alert) {
            $wanted[] = $this->alertKey($alert['minutes'], $alert['at'], $alert['channel']);
        }

        $kept = [];

        foreach ($event->getAlerts() as $existing) {
            $key = $this->alertKey(
                $existing->getMinutesBefore(),
                $existing->isRelative() ? null : $existing->getRemindAt(),
                $existing->getChannel(),
            );

            if (in_array($key, $wanted, true)) {
                $kept[] = $key;

                continue;
            }

            $event->removeAlert($existing);
            $this->entityManager->remove($existing);
        }

        foreach ($alerts as $alert) {
            $key = $this->alertKey($alert['minutes'], $alert['at'], $alert['channel']);
            if (in_array($key, $kept, true)) {
                continue;
            }

            $created = new PlanningEventAlert();
            // Added to the event first, because `setAbsoluteAt` writes `remindAt`
            // directly and `addAlert` would otherwise recompute it from the
            // default offset the moment the two sides are joined.
            $event->addAlert($created);
            $created->setChannel($alert['channel']);

            if (null !== $alert['minutes']) {
                $created->setMinutesBefore($alert['minutes']);
            } elseif ($alert['at'] instanceof DateTimeImmutable) {
                $created->setAbsoluteAt($alert['at']);
            }

            $this->entityManager->persist($created);
        }
    }

    /**
     * Brings the invitation list in line with the ids the form sent.
     *
     * Diffed rather than cleared and rebuilt, and here the difference is the whole
     * feature: an attendee who survives an edit keeps their answer, so renaming an
     * event does not un-accept everybody who had already said yes.
     *
     * Somebody newly invited is told. An invitation nobody hears about is a row in
     * a table.
     *
     * @param list<int> $ids
     */
    protected function applyAttendees(PlanningEventInterface $event, array $ids): void
    {
        $kept = [];

        foreach ($event->getAttendees() as $existing) {
            $userId = (int) $existing->getUser()->getId();

            if (in_array($userId, $ids, true)) {
                $kept[] = $userId;

                continue;
            }

            $event->removeAttendee($existing);
            $this->entityManager->remove($existing);
        }

        foreach ($ids as $id) {
            if (in_array($id, $kept, true)) {
                continue;
            }

            $user = $this->users->find($id);
            // An id that names nobody is dropped rather than refused: it means a
            // stale list or a hand-written request, and failing the save of an
            // otherwise valid event over it would be the wrong trade.
            if (!$user instanceof CoreUserInterface) {
                continue;
            }

            $attendee = new PlanningEventAttendee();
            $attendee->setUser($user);
            $event->addAttendee($attendee);
            $this->entityManager->persist($attendee);

            $this->newlyInvited[] = $user;
        }
    }

    /**
     * Tells whoever was just invited, once the event is safely written.
     *
     * Cleared as it goes, so a second write in the same request cannot re-announce
     * the first one's invitations.
     */
    protected function notifyInvitations(PlanningEventInterface $event): void
    {
        $invited = $this->newlyInvited;
        $this->newlyInvited = [];

        foreach ($invited as $user) {
            $this->inviteNotification($user, $event);
        }
    }

    private function inviteNotification(CoreUserInterface $user, PlanningEventInterface $event): void
    {
        $this->notifications->notify(
            $user,
            'planning.invitation',
            $event->getTitle(),
            $this->translator->trans('backend.plannings.attendees.invited_body', [
                '%calendar%' => $event->getPlanning()->getName(),
            ]),
            $this->urlGenerator->generate('backend_planning_calendar', [
                'view' => 'day',
                'date' => $event->getStartAt()->format('Y-m-d'),
            ]),
            ['eventId' => $event->getId()],
        );
    }

    /**
     * What makes two alerts the same alert.
     *
     * One string rather than three comparisons, so the diff above cannot compare
     * two of the three parts and miss the last - which is exactly what happened
     * when the channel arrived.
     */
    private function alertKey(?int $minutes, ?DateTimeImmutable $at, PlanningAlertChannelEnum $channel): string
    {
        return sprintf(
            '%s|%s|%s',
            $minutes ?? 'x',
            $at?->getTimestamp() ?? 'x',
            $channel->value,
        );
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
