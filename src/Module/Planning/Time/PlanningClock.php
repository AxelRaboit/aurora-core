<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Time;

use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

use function is_string;
use function mb_trim;

/**
 * The module's two rules about time, in the one place that states them.
 *
 * Every instant in this module's tables is UTC, and the only place a wall clock
 * exists is a screen. That single sentence had eight implementations: five
 * resolving a calendar's zone with a fallback, three parsing a request into UTC -
 * `OccurrenceExpander`, `IcalWriter`, `PlanningEventManager`, `PlanningNotifier`
 * twice, and the three `date()` methods on the controller and the two input
 * factories.
 *
 * They agreed, which is the reason to collect them rather than a reason not to:
 * this exact rule has already been broken twice by a copy that was not updated -
 * once when the form sent a naked wall clock, and once when the controller's
 * `date()` never normalised at all and stored `occurrenceAt` two hours out.
 * Neither was visible until an event drew itself twice.
 *
 * Static because there is nothing to inject and nothing to configure. A service
 * would put a constructor argument on six classes to reach two `try` blocks, and
 * the rule is not one an application should be able to swap.
 */
final class PlanningClock
{
    /**
     * A calendar's timezone, or UTC when it names one that does not exist.
     *
     * A stored zone the database no longer knows is not a reason to refuse a save
     * or drop a feed. UTC is the honest fallback: it is what the column holds
     * anyway, so the worst case is a time shown in the zone it was stored in.
     */
    public static function zone(PlanningInterface $planning): DateTimeZone
    {
        try {
            return new DateTimeZone($planning->getTimezone());
        } catch (Exception) {
            return self::utcZone();
        }
    }

    /**
     * An instant out of whatever a request sent, normalised to UTC. Null when it
     * sent nothing usable.
     *
     * Normalising here and not at the column is the whole point: a browser may
     * send `2026-09-01T10:00+02:00`, or the same moment as `08:00Z`, or - the case
     * that bit - `2026-09-01T10:00` with no offset at all, which PHP reads in the
     * process timezone. Two of those three are the same instant and the third is a
     * bug, and the only way to tell is to convert every one of them at the edge.
     *
     * A malformed string is null rather than an exception: it comes from a form,
     * and a rejected field is the caller's to report.
     */
    public static function utc(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || '' === mb_trim($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value)->setTimezone(self::utcZone());
        } catch (Exception) {
            return null;
        }
    }

    /** The zone every stored instant is in. */
    public static function utcZone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}
