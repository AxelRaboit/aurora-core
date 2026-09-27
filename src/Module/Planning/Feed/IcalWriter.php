<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Feed;

use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Planning\Reminder\Entity\PlanningReminderInterface;
use Aurora\Module\Planning\Time\PlanningClock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * One calendar as an iCalendar document.
 *
 * Hand-written rather than pulled from a library, because the subset a feed needs
 * is small and closed - a VCALENDAR, VEVENTs, VTODOs - and the parts that are
 * actually easy to get wrong are escaping and line folding, which a library would
 * not save us from having to test anyway.
 *
 * Events become VEVENT and reminders become VTODO, which is what they are: a
 * VTODO has a due date and a completion state, and flattening a reminder into an
 * event would lose the checkbox that makes it a reminder.
 */
final readonly class IcalWriter
{
    /**
     * Identifies this software in every file it writes, as the format requires.
     *
     * Not versioned: a PRODID that changes with the application would make every
     * feed look like it came from a different program each release.
     */
    private const string PRODID = '-//Aurora//Planning//EN';

    /** RFC 5545 folds at 75 octets, and counts the CRLF outside that. */
    private const int FOLD_AT = 75;

    /**
     * Several calendars in one feed.
     *
     * A share link can point at more than one, and a guest who was given the
     * shoots and the deadlines together wants one subscription rather than two -
     * which is most of the reason the link is a row and not a column.
     *
     * The name and the zone are passed in rather than taken from the first
     * calendar. There is one of each in a VCALENDAR and several calendars behind
     * it, so guessing from the first would name the feed after whichever happened
     * to be added first.
     *
     * @param list<PlanningInterface> $plannings
     */
    public function writeMany(array $plannings, string $name, string $timezone): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:'.self::PRODID,
            // Not part of the standard, and every calendar application reads it.
            // Without it a subscribed feed shows up named after its URL.
            'X-WR-CALNAME:'.$this->escape($name),
            'X-WR-TIMEZONE:'.$this->escape($timezone),
            // A quarter of an hour is what Google publishes and what phones
            // respect; asking for less is ignored and asking for more is rude.
            'X-PUBLISHED-TTL:PT15M',
        ];

        $components = [];
        /** @var array<string, int> $zones the zones a series is written in, and its first year */
        $zones = [];

        foreach ($plannings as $planning) {
            foreach ($planning->getEvents() as $event) {
                $components = [...$components, ...$this->event($event)];

                if ($this->isWrittenInItsZone($event)) {
                    $zone = PlanningClock::zone($event->getPlanning())->getName();
                    $zones[$zone] = min($zones[$zone] ?? PHP_INT_MAX, (int) $event->getStartAt()->format('Y'));
                }
            }

            foreach ($planning->getReminders() as $reminder) {
                $components = [...$components, ...$this->reminder($reminder)];
            }
        }

        // The zones before the events that name them, as the format asks.
        foreach ($zones as $zone => $firstYear) {
            $lines = [...$lines, ...$this->timezone(new DateTimeZone($zone), $firstYear)];
        }

        $lines = [...$lines, ...$components];
        $lines[] = 'END:VCALENDAR';

        // CRLF, which the format requires rather than prefers: some readers treat
        // a bare LF as a malformed file and show nothing at all.
        return implode("\r\n", array_merge(...array_map($this->fold(...), $lines)))."\r\n";
    }

    /** @return list<string> */
    private function event(PlanningEventInterface $event): array
    {
        $lines = [
            'BEGIN:VEVENT',
            'UID:'.$this->uid('event', (int) $event->getId()),
            'DTSTAMP:'.$this->stamp($event->getUpdatedAt()),
            'SUMMARY:'.$this->escape($event->getTitle()),
        ];

        $zone = PlanningClock::zone($event->getPlanning());

        if ($event->isAllDay()) {
            // A whole day is a date and not an instant, and its end is exclusive -
            // a one-day event ends on the following day. Written in the calendar's
            // zone, because that is the zone whose days these are.
            $lines[] = 'DTSTART;VALUE=DATE:'.$event->getStartAt()->setTimezone($zone)->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$event->getEndAt()->setTimezone($zone)->modify('+1 day')->format('Ymd');
        } elseif ($this->isWrittenInItsZone($event)) {
            // A series is written in its calendar's zone and not in UTC: the
            // reader repeats the rule in the zone of DTSTART, and a weekly
            // 10:00 in Paris repeated in UTC becomes 11:00 once the clocks
            // change. The expander repeats it in the same zone, for the same
            // reason.
            $lines[] = sprintf('DTSTART;TZID=%s:%s', $zone->getName(), $event->getStartAt()->setTimezone($zone)->format('Ymd\THis'));
            $lines[] = sprintf('DTEND;TZID=%s:%s', $zone->getName(), $event->getEndAt()->setTimezone($zone)->format('Ymd\THis'));
        } else {
            $lines[] = 'DTSTART:'.$this->stamp($event->getStartAt());
            $lines[] = 'DTEND:'.$this->stamp($event->getEndAt());
        }

        $lines = [...$lines, ...$this->recurrence($event, $zone)];

        if (null !== $event->getDescription()) {
            $lines[] = 'DESCRIPTION:'.$this->escape($event->getDescription());
        }

        if (null !== $event->getLocation()) {
            $lines[] = 'LOCATION:'.$this->escape($event->getLocation());
        }

        foreach ($event->getAttendees() as $attendee) {
            // The address is the identity here, which is what the format wants -
            // and PARTSTAT is the answer, so a subscribed calendar shows who is
            // coming rather than only who was asked.
            $lines[] = sprintf(
                'ATTENDEE;CN=%s;PARTSTAT=%s:mailto:%s',
                $this->escape($attendee->getUser()->getName()),
                $attendee->getStatus()->toIcal(),
                $attendee->getUser()->getEmail(),
            );
        }

        $lines[] = 'STATUS:'.$this->status($event);
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /** @return list<string> */
    private function reminder(PlanningReminderInterface $reminder): array
    {
        $lines = [
            'BEGIN:VTODO',
            'UID:'.$this->uid('reminder', (int) $reminder->getId()),
            'DTSTAMP:'.$this->stamp($reminder->getUpdatedAt()),
            'SUMMARY:'.$this->escape($reminder->getTitle()),
        ];

        if ($reminder->isAllDay()) {
            $lines[] = 'DUE;VALUE=DATE:'.$reminder->getDueAt()
                ->setTimezone(PlanningClock::zone($reminder->getPlanning()))
                ->format('Ymd');
        } else {
            $lines[] = 'DUE:'.$this->stamp($reminder->getDueAt());
        }

        if (null !== $reminder->getNotes()) {
            $lines[] = 'DESCRIPTION:'.$this->escape($reminder->getNotes());
        }

        $completedAt = $reminder->getCompletedAt();
        if ($completedAt instanceof DateTimeImmutable) {
            $lines[] = 'STATUS:COMPLETED';
            // Both, because readers disagree on which they trust: some hide a
            // VTODO on STATUS and others on PERCENT-COMPLETE.
            $lines[] = 'PERCENT-COMPLETE:100';
            $lines[] = 'COMPLETED:'.$this->stamp($completedAt);
        } else {
            $lines[] = 'STATUS:NEEDS-ACTION';
        }

        $lines[] = 'END:VTODO';

        return $lines;
    }

    /**
     * The rule of a series, and the dates it no longer produces.
     *
     * Without these a subscribed calendar showed a series as its first
     * occurrence only. The dates left out are the occurrences somebody
     * deleted and the ones somebody edited: an edited occurrence is a row of
     * its own, written as its own event, and the series must not also draw it
     * at its old time.
     *
     * @return list<string>
     */
    private function recurrence(PlanningEventInterface $event, DateTimeZone $zone): array
    {
        $rrule = $event->getRrule();
        if (null === $rrule || '' === $rrule) {
            return [];
        }

        $lines = ['RRULE:'.$rrule];

        $skipped = $event->getExdates();
        foreach ($event->getOccurrences() as $edited) {
            $at = $edited->getOccurrenceAt();
            if ($at instanceof DateTimeImmutable) {
                $skipped[] = $at->format(DATE_ATOM);
            }
        }

        if ([] === $skipped) {
            return $lines;
        }

        $dates = [];
        foreach (array_unique($skipped) as $at) {
            $local = new DateTimeImmutable($at)->setTimezone($zone);
            $dates[] = match (true) {
                $event->isAllDay() => $local->format('Ymd'),
                $this->isWrittenInItsZone($event) => $local->format('Ymd\THis'),
                default => $this->stamp($local),
            };
        }

        sort($dates);

        $lines[] = match (true) {
            $event->isAllDay() => 'EXDATE;VALUE=DATE:'.implode(',', $dates),
            $this->isWrittenInItsZone($event) => sprintf('EXDATE;TZID=%s:%s', $zone->getName(), implode(',', $dates)),
            default => 'EXDATE:'.implode(',', $dates),
        };

        return $lines;
    }

    /**
     * A timed series, in a calendar whose zone is not UTC itself.
     *
     * Single events stay in UTC: an instant needs no zone, and a reader
     * converts it. A series is a rule, and a rule needs the zone it repeats in.
     */
    private function isWrittenInItsZone(PlanningEventInterface $event): bool
    {
        return null !== $event->getRrule()
            && '' !== $event->getRrule()
            && !$event->isAllDay()
            && 'UTC' !== PlanningClock::zone($event->getPlanning())->getName();
    }

    /**
     * A zone as the format describes one: its changes of offset, spelled out.
     *
     * Some readers know the IANA names and ignore this, others need it to
     * place a `TZID` at all. Written from PHP's own table of transitions, from
     * the first series' year to ten years ahead, rather than as a rule of
     * their own: the table already knows every past change of the rules, and
     * a hand-written "last Sunday of March" would be wrong for the years it
     * was not.
     *
     * @return list<string>
     */
    private function timezone(DateTimeZone $zone, int $firstYear): array
    {
        $from = new DateTimeImmutable(sprintf('%d-01-01 00:00:00', $firstYear), new DateTimeZone('UTC'));
        $to = new DateTimeImmutable('first day of january next year', new DateTimeZone('UTC'))->modify('+10 years');
        $transitions = $zone->getTransitions($from->getTimestamp(), $to->getTimestamp());

        $lines = ['BEGIN:VTIMEZONE', 'TZID:'.$zone->getName()];

        // The first entry is the state at the start of the range, not a change.
        $offset = (int) ($transitions[0]['offset'] ?? $zone->getOffset($from));
        $lines = [...$lines, ...$this->observance(
            (bool) ($transitions[0]['isdst'] ?? false),
            gmdate('Ymd\THis', $from->getTimestamp() + $offset),
            $offset,
            $offset,
            (string) ($transitions[0]['abbr'] ?? ''),
        )];

        foreach (array_slice($transitions, 1) as $transition) {
            $lines = [...$lines, ...$this->observance(
                (bool) $transition['isdst'],
                // The local time the change happens at, on the clock before it.
                gmdate('Ymd\THis', (int) $transition['ts'] + $offset),
                $offset,
                (int) $transition['offset'],
                (string) $transition['abbr'],
            )];
            $offset = (int) $transition['offset'];
        }

        $lines[] = 'END:VTIMEZONE';

        return $lines;
    }

    /** @return list<string> */
    private function observance(bool $daylight, string $start, int $from, int $to, string $name): array
    {
        $kind = $daylight ? 'DAYLIGHT' : 'STANDARD';

        return [
            'BEGIN:'.$kind,
            'DTSTART:'.$start,
            'TZOFFSETFROM:'.$this->offset($from),
            'TZOFFSETTO:'.$this->offset($to),
            ...('' === $name ? [] : ['TZNAME:'.$this->escape($name)]),
            'END:'.$kind,
        ];
    }

    private function offset(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);

        return sprintf('%s%02d%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    private function status(PlanningEventInterface $event): string
    {
        return match ($event->getStatus()->value) {
            'cancelled' => 'CANCELLED',
            'tentative' => 'TENTATIVE',
            default => 'CONFIRMED',
        };
    }

    /**
     * A stable identity for one row, in the form the format wants.
     *
     * Stable is the whole requirement: a reader matches what it already has
     * against these, so a UID that changed between fetches would make every event
     * arrive as a new one and the old one linger.
     */
    private function uid(string $kind, int $id): string
    {
        return sprintf('%s-%d@aurora.planning', $kind, $id);
    }

    /** UTC, with the Z the format requires to mean it. */
    private function stamp(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /**
     * The four characters the format reserves inside a text value.
     *
     * Order matters: the backslash has to go first, or the escapes added after it
     * would be escaped again.
     */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\;', '\\,'],
            $value,
        );
    }

    /**
     * One logical line as however many physical lines the format allows.
     *
     * Folded on octets and not characters, because that is what the standard
     * counts - and a naive split at 75 characters can cut a multi-byte character
     * in half, which is how a feed with one accented title in it fails to parse.
     * Continuation lines begin with a space, which the reader strips.
     *
     * @return list<string>
     */
    private function fold(string $line): array
    {
        if (mb_strlen($line) <= self::FOLD_AT) {
            return [$line];
        }

        $out = [];
        $remaining = $line;
        $limit = self::FOLD_AT;

        while (mb_strlen($remaining) > $limit) {
            // `mb_strcut` cuts on a character boundary at or before the byte
            // limit, which is exactly the guarantee needed here.
            $chunk = mb_strcut($remaining, 0, $limit);
            $out[] = $chunk;
            $remaining = mb_substr($remaining, mb_strlen($chunk));
            // A continuation line spends one of its octets on the leading space.
            $limit = self::FOLD_AT - 1;
        }

        $out[] = $remaining;

        return array_merge([array_shift($out)], array_map(static fn (string $part): string => ' '.$part, $out));
    }
}
