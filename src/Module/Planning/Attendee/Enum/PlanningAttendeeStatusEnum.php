<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Attendee\Enum;

/**
 * Whether somebody invited to an event is coming.
 *
 * The four the standard uses, which are also the four Google and Apple show. A
 * list of attendees without this is a list of names: it says who was asked and not
 * who will be there, which is the only part an organiser acts on.
 */
enum PlanningAttendeeStatusEnum: string
{
    /** Invited and has not answered. Where everybody starts. */
    case NeedsAction = 'needs_action';

    case Accepted = 'accepted';

    case Declined = 'declined';

    /** Coming, probably. The standard's TENTATIVE. */
    case Tentative = 'tentative';

    public function getLabelKey(): string
    {
        return 'backend.plannings.attendees.status_'.$this->value;
    }

    /**
     * The badge colour, from the same vocabulary the rest of the backend uses.
     *
     * Grey for an answer that has not come: an unanswered invitation is not a
     * problem, it is a wait, and colouring it amber would ask the organiser to act
     * on something nobody has done yet.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Accepted => 'emerald',
            self::Declined => 'red',
            self::Tentative => 'amber',
            self::NeedsAction => 'slate',
        };
    }

    /** The word iCalendar uses, which is not the word the column uses. */
    public function toIcal(): string
    {
        return match ($this) {
            self::NeedsAction => 'NEEDS-ACTION',
            self::Accepted => 'ACCEPTED',
            self::Declined => 'DECLINED',
            self::Tentative => 'TENTATIVE',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
