<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Manager;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Planning\Event\Dto\PlanningEventInputInterface;
use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Entity\PlanningEventAlert;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
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

        $startAt = $input->getStartAt();
        $endAt = $input->getEndAt();

        if ($input->isAllDay()) {
            [$startAt, $endAt] = $this->snapToWholeDays($startAt, $endAt, $event->getPlanning());
        }

        // Last, and in one call: the entity refuses an end before a start, so
        // the two have to arrive together and after nothing else can throw.
        $event->setSpan($startAt, $endAt);
        $this->applyAlerts($event, $input->getAlerts());
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
        $utc = new DateTimeZone('UTC');

        try {
            $local = new DateTimeZone($planning->getTimezone());
        } catch (Exception) {
            // A stored timezone that no longer exists in the database is not a
            // reason to refuse the save. UTC is the honest fallback: it is what
            // the column holds anyway.
            $local = $utc;
        }

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
     * @param list<array{minutes: int|null, at: DateTimeImmutable|null}> $alerts
     */
    protected function applyAlerts(PlanningEventInterface $event, array $alerts): void
    {
        $wantedOffsets = [];
        $wantedMoments = [];
        foreach ($alerts as $alert) {
            if (null !== $alert['minutes']) {
                $wantedOffsets[] = $alert['minutes'];

                continue;
            }

            if ($alert['at'] instanceof DateTimeImmutable) {
                $wantedMoments[] = $alert['at']->getTimestamp();
            }
        }

        $keptOffsets = [];
        $keptMoments = [];

        foreach ($event->getAlerts() as $existing) {
            if ($existing->isRelative()) {
                if (in_array($existing->getMinutesBefore(), $wantedOffsets, true)) {
                    $keptOffsets[] = $existing->getMinutesBefore();

                    continue;
                }
            } elseif (in_array($existing->getRemindAt()->getTimestamp(), $wantedMoments, true)) {
                $keptMoments[] = $existing->getRemindAt()->getTimestamp();

                continue;
            }

            $event->removeAlert($existing);
            $this->entityManager->remove($existing);
        }

        foreach ($alerts as $alert) {
            if (null !== $alert['minutes']) {
                if (in_array($alert['minutes'], $keptOffsets, true)) {
                    continue;
                }

                $created = new PlanningEventAlert();
                $event->addAlert($created);
                $created->setMinutesBefore($alert['minutes']);
                $this->entityManager->persist($created);

                continue;
            }

            if (!$alert['at'] instanceof DateTimeImmutable) {
                continue;
            }

            if (in_array($alert['at']->getTimestamp(), $keptMoments, true)) {
                continue;
            }

            $created = new PlanningEventAlert();
            // Added to the event first, because `setAbsoluteAt` writes `remindAt`
            // directly and `addAlert` would otherwise recompute it from the
            // default offset the moment the two sides are joined.
            $event->addAlert($created);
            $created->setAbsoluteAt($alert['at']);
            $this->entityManager->persist($created);
        }
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
