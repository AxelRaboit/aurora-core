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

    public function write(PlanningInterface $planning): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:'.self::PRODID,
            // Not part of the standard, and every calendar application reads it.
            // Without it a subscribed feed shows up named after its URL.
            'X-WR-CALNAME:'.$this->escape($planning->getName()),
            'X-WR-TIMEZONE:'.$this->escape($planning->getTimezone()),
            // A quarter of an hour is what Google publishes and what phones
            // respect; asking for less is ignored and asking for more is rude.
            'X-PUBLISHED-TTL:PT15M',
        ];

        foreach ($planning->getEvents() as $event) {
            $lines = [...$lines, ...$this->event($event)];
        }

        foreach ($planning->getReminders() as $reminder) {
            $lines = [...$lines, ...$this->reminder($reminder)];
        }

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

        if ($event->isAllDay()) {
            // A whole day is a date and not an instant, and its end is exclusive -
            // a one-day event ends on the following day. Written in the calendar's
            // zone, because that is the zone whose days these are.
            $zone = PlanningClock::zone($event->getPlanning());
            $lines[] = 'DTSTART;VALUE=DATE:'.$event->getStartAt()->setTimezone($zone)->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$event->getEndAt()->setTimezone($zone)->modify('+1 day')->format('Ymd');
        } else {
            $lines[] = 'DTSTART:'.$this->stamp($event->getStartAt());
            $lines[] = 'DTEND:'.$this->stamp($event->getEndAt());
        }

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
