<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Recurrence;

use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Recurr\Exception\InvalidRRule;
use Recurr\Exception\InvalidWeekday;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;
use Recurr\Transformer\Constraint\BetweenConstraint;

/**
 * Turns a rule into the occurrences that fall inside a window.
 *
 * Expansion runs through `simshaun/recurr` rather than a rule reader of our own,
 * and that is a deliberate dependency. The hard parts of RFC 5545 are not the
 * syntax, they are the behaviours: a weekly series keeps its wall-clock time
 * across a daylight-saving change, `BYMONTHDAY=31` skips February rather than
 * clamping to the 28th, and `COUNT` counts occurrences the rule produced rather
 * than days elapsed. Each of those is a defect that hides for months in a
 * hand-rolled expander, and none of them is interesting to own.
 *
 * The rule is expanded **in the calendar's timezone**, because that is what a
 * recurring time means: "every Monday at 09:00" is 09:00 where the calendar
 * lives, whatever UTC is doing that week. The results come back as UTC instants,
 * because that is what the column holds.
 */
final readonly class OccurrenceExpander
{
    /**
     * A ceiling on what one window may produce.
     *
     * An hourly rule over a month is 720 occurrences and a screen cannot use
     * them; without a limit, a rule nobody meant to write would be a request that
     * never returns. High enough that no real series reaches it.
     */
    private const int MAX_PER_WINDOW = 750;

    /**
     * The occurrences of one series between two instants.
     *
     * Excludes the dates somebody deleted, and the dates that have become rows of
     * their own - a generated appearance and its edited replacement must never
     * both be drawn, and the replacement is returned by the plain query that finds
     * every other row.
     *
     * @return list<PlanningOccurrence>
     */
    public function expand(
        PlanningEventInterface $master,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $rrule = $master->getRrule();
        if (null === $rrule) {
            return [];
        }

        $zone = $this->zone($master);
        $duration = $master->getEndAt()->getTimestamp() - $master->getStartAt()->getTimestamp();
        $skip = $this->datesToSkip($master);

        try {
            $rule = new Rule(
                $rrule,
                DateTime::createFromImmutable($master->getStartAt()->setTimezone($zone)),
                null,
                $zone->getName(),
            );

            $config = new ArrayTransformerConfig();
            // Asking for one more than the ceiling is how the caller can tell a
            // full window from a truncated one, if it ever needs to.
            $config->setVirtualLimit(self::MAX_PER_WINDOW + 1);

            $transformer = new ArrayTransformer($config);
            // Constrained rather than filtered afterwards: an unbounded weekly
            // rule from 2020 would otherwise be expanded from 2020 to the window
            // on every fetch, for ever.
            $collection = $transformer->transform(
                $rule,
                new BetweenConstraint(
                    DateTime::createFromImmutable($from->setTimezone($zone)),
                    DateTime::createFromImmutable($to->setTimezone($zone)),
                ),
            );
        } catch (InvalidRRule|InvalidWeekday) {
            // A rule the library refuses is a rule nothing can draw. Answering
            // with nothing keeps one bad row from emptying a whole month.
            return [];
        }

        $utc = new DateTimeZone('UTC');
        $out = [];

        foreach ($collection as $occurrence) {
            $startAt = DateTimeImmutable::createFromMutable($occurrence->getStart())->setTimezone($utc);
            $key = $startAt->format(DATE_ATOM);

            if (in_array($key, $skip, true)) {
                continue;
            }

            $out[] = new PlanningOccurrence(
                $master,
                $startAt,
                // The duration is carried rather than recomputed from the rule:
                // an occurrence is as long as the event, and asking the rule how
                // long anything is would be asking the wrong question.
                $startAt->modify(sprintf('+%d seconds', $duration)),
                $startAt,
            );

            if (count($out) >= self::MAX_PER_WINDOW) {
                break;
            }
        }

        return $out;
    }

    /**
     * When the last occurrence starts, or null for a series with no end.
     *
     * Used to fill `recurrenceUntil` on write, which is what keeps the window
     * query from having to expand every rule in the table. Bounded by the
     * library's own limit: an endless rule has no last occurrence and asking for
     * one has to stop somewhere.
     */
    public function lastStart(PlanningEventInterface $master): ?DateTimeImmutable
    {
        $rrule = $master->getRrule();
        if (null === $rrule) {
            return null;
        }

        $zone = $this->zone($master);

        try {
            $rule = new Rule(
                $rrule,
                DateTime::createFromImmutable($master->getStartAt()->setTimezone($zone)),
                null,
                $zone->getName(),
            );

            // Only a rule that says where it stops has a last occurrence. Asking
            // an endless one would return the library's virtual limit, which is a
            // number about the library rather than about the series.
            if (null === $rule->getUntil() && null === $rule->getCount()) {
                return null;
            }

            $collection = new ArrayTransformer()->transform($rule);
        } catch (InvalidRRule|InvalidWeekday) {
            return null;
        }

        $last = $collection->last();

        return false === $last
            ? null
            : DateTimeImmutable::createFromMutable($last->getStart())->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The generated dates this series must not emit.
     *
     * Two sources with one meaning: an occurrence somebody deleted, and one
     * somebody edited into a row of its own. Compared as UTC strings in one
     * spelling, because two spellings of the same moment would never match.
     *
     * @return list<string>
     */
    private function datesToSkip(PlanningEventInterface $master): array
    {
        $skip = $master->getExdates();

        foreach ($master->getOccurrences() as $child) {
            $at = $child->getOccurrenceAt();
            if ($at instanceof DateTimeImmutable) {
                $skip[] = $at->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
            }
        }

        return $skip;
    }

    private function zone(PlanningEventInterface $master): DateTimeZone
    {
        try {
            return new DateTimeZone($master->getPlanning()->getTimezone());
        } catch (Exception) {
            return new DateTimeZone('UTC');
        }
    }
}
