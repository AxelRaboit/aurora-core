<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Recurrence;

use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * The three things an edit to a series can mean.
 *
 * This is where recurrence stops being arithmetic and becomes decisions. Each
 * scope is a different write, and getting one wrong is not a rendering bug - it
 * silently changes appointments somebody else is relying on.
 *
 * `This` detaches one occurrence into a row of its own. `All` writes the series.
 * `Following` splits it: the original stops just before the occurrence, and a new
 * series starts at it carrying the same rule.
 *
 * Splitting rather than rewriting the rule in place, because the occurrences
 * already past belong to what was agreed. Moving a Monday meeting to Tuesday "from
 * now on" must not rewrite the history of every Monday it already happened on.
 */
final readonly class RecurrenceEditor
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OccurrenceExpander $expander,
    ) {}

    /**
     * Detaches one occurrence so it can be edited on its own.
     *
     * Returns the row to write to. The series keeps its rule and stops emitting
     * that date, because the child now claims it.
     */
    public function detach(PlanningEventInterface $master, DateTimeImmutable $occurrenceAt): PlanningEventInterface
    {
        $this->refuseIfNotASeries($master);

        $existing = $this->findChild($master, $occurrenceAt);
        if ($existing instanceof PlanningEventInterface) {
            // Already detached. Editing the same occurrence twice is ordinary, and
            // a second child claiming the same date would break the unique index
            // on a perfectly reasonable save.
            return $existing;
        }

        $child = new PlanningEvent();
        $child->setPlanning($master->getPlanning());
        $child->setTitle($master->getTitle());
        $child->setDescription($master->getDescription());
        $child->setLocation($master->getLocation());
        $child->setAllDay($master->isAllDay());
        $child->setStatus($master->getStatus());
        $child->setColourSlot($master->getColourSlot());
        $child->setMaster($master);
        $child->setOccurrenceAt($occurrenceAt);
        // The occurrence's own span, not the master's: the master's is the first
        // occurrence, and detaching the fifth one has to start from the fifth.
        $child->setSpan($occurrenceAt, $occurrenceAt->modify(sprintf(
            '+%d seconds',
            $master->getEndAt()->getTimestamp() - $master->getStartAt()->getTimestamp(),
        )));

        $this->entityManager->persist($child);

        return $child;
    }

    /**
     * Removes one occurrence from a series.
     *
     * An exdate rather than a detached row with a "cancelled" status: the reader
     * asked for it to be gone, and a cancelled row would still be something the
     * grid has to decide whether to draw.
     *
     * A detached child at that date goes too, or the date would come back as the
     * exception nobody wanted.
     */
    public function removeOccurrence(PlanningEventInterface $master, DateTimeImmutable $occurrenceAt): void
    {
        $this->refuseIfNotASeries($master);

        $child = $this->findChild($master, $occurrenceAt);
        if ($child instanceof PlanningEventInterface) {
            $master->getOccurrences()->removeElement($child);
            $this->entityManager->remove($child);
        }

        $master->excludeOccurrence($occurrenceAt);
    }

    /**
     * Splits a series in two at one occurrence.
     *
     * The original is told to stop just before that date and keeps everything
     * already past; the returned row is a new series starting at it, carrying the
     * same rule and the same alerts. The caller writes its changes to that one.
     *
     * Detached children after the split point move across with it: an exception
     * somebody made to the third Monday still belongs to the third Monday after
     * the series is cut at the second.
     */
    public function split(PlanningEventInterface $master, DateTimeImmutable $occurrenceAt): PlanningEventInterface
    {
        $this->refuseIfNotASeries($master);

        $tail = new PlanningEvent();
        $tail->setPlanning($master->getPlanning());
        $tail->setTitle($master->getTitle());
        $tail->setDescription($master->getDescription());
        $tail->setLocation($master->getLocation());
        $tail->setAllDay($master->isAllDay());
        $tail->setStatus($master->getStatus());
        $tail->setColourSlot($master->getColourSlot());
        $tail->setRrule($master->getRrule());
        $tail->setSpan($occurrenceAt, $occurrenceAt->modify(sprintf(
            '+%d seconds',
            $master->getEndAt()->getTimestamp() - $master->getStartAt()->getTimestamp(),
        )));

        // The exdates that fall in the tail's half go with it, and the ones before
        // stay: a deleted occurrence belongs to whichever series now owns its date.
        $head = [];
        $moved = [];
        foreach ($master->getExdates() as $exdate) {
            $at = new DateTimeImmutable($exdate);
            if ($at < $occurrenceAt) {
                $head[] = $exdate;
            } else {
                $moved[] = $exdate;
            }
        }

        $master->setExdates($head);
        $tail->setExdates($moved);

        foreach ($master->getOccurrences() as $child) {
            $at = $child->getOccurrenceAt();
            if ($at instanceof DateTimeImmutable && $at >= $occurrenceAt) {
                $child->setMaster($tail);
            }
        }

        $this->entityManager->persist($tail);

        // Last, because it reads the rule the tail now carries too, and because
        // stopping the head is what makes the two halves disjoint.
        $this->endBefore($master, $occurrenceAt);
        $this->refreshUntil($tail);

        return $tail;
    }

    /**
     * Tells a series to stop before an instant.
     *
     * Written as `UNTIL` on the rule rather than as a column of our own, so the
     * rule stays the whole truth about when the series runs - and travels intact
     * through the iCalendar feed.
     */
    public function endBefore(PlanningEventInterface $master, DateTimeImmutable $before): void
    {
        $rrule = $master->getRrule();
        if (null === $rrule) {
            return;
        }

        // A second before, because UNTIL is inclusive: using the occurrence's own
        // instant would leave it in both halves.
        $until = $before->modify('-1 second')->setTimezone(new DateTimeZone('UTC'));

        $parts = [];
        foreach (explode(';', $rrule) as $part) {
            $name = mb_strtoupper(explode('=', $part)[0]);
            // COUNT and UNTIL are mutually exclusive in the standard, so the one
            // being set has to take the other's place.
            if ('' !== $part && !in_array($name, ['UNTIL', 'COUNT'], true)) {
                $parts[] = $part;
            }
        }

        $parts[] = 'UNTIL='.$until->format('Ymd\THis\Z');
        $master->setRrule(implode(';', $parts));

        $this->refreshUntil($master);
    }

    /**
     * Recomputes the denormalised end of a series.
     *
     * Called after anything that could move it, because the window query trusts
     * this column and a stale value makes a series disappear from a month it
     * belongs in.
     */
    public function refreshUntil(PlanningEventInterface $master): void
    {
        if (!$master->isRecurring()) {
            $master->setRecurrenceUntil(null);

            return;
        }

        $lastStart = $this->expander->lastStart($master);

        $master->setRecurrenceUntil($lastStart?->modify(sprintf(
            '+%d seconds',
            $master->getEndAt()->getTimestamp() - $master->getStartAt()->getTimestamp(),
        )));
    }

    private function findChild(PlanningEventInterface $master, DateTimeImmutable $occurrenceAt): ?PlanningEventInterface
    {
        foreach ($master->getOccurrences() as $child) {
            if ($child->getOccurrenceAt()?->getTimestamp() === $occurrenceAt->getTimestamp()) {
                return $child;
            }
        }

        return null;
    }

    private function refuseIfNotASeries(PlanningEventInterface $master): void
    {
        if (!$master->isRecurring()) {
            throw new RuntimeException('This event is not a series, so a scope cannot be applied to it.');
        }
    }
}
