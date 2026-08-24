<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\View;

use Aurora\Module\Planning\Link\Entity\PlanningShareLinkInterface;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;

use function array_map;
use function array_values;

/**
 * What a guest's page needs, and deliberately no more.
 *
 * The backend's payload carries the timezone list, the invitable accounts, the
 * reader's own id and the routes for eight writes. None of that belongs on a page
 * reached by a secret in a URL: a guest cannot change a timezone, has nobody to
 * invite, and is not an account. Sending it would be handing over a description of
 * the application to somebody who was given one calendar.
 *
 * So this is a separate builder rather than a flag on the other one. A shared
 * builder with an `$asGuest` argument is a payload whose safety depends on
 * remembering the argument.
 */
final readonly class PlanningShareViewBuilder
{
    /** @return array<string, mixed> */
    public function pageView(PlanningShareLinkInterface $link): array
    {
        $calendars = array_values($link->getCalendars()->toArray());

        return [
            'token' => $link->getToken(),
            'label' => $link->getLabel(),
            'expiresAt' => $link->getExpiresAt(),
            'calendars' => array_map($this->guestCalendar(...), $calendars),
            // The first calendar's zone, because the guest has no stored preference
            // and no control to change one. The owner chose it when they made the
            // calendar, which is the closest thing to an answer available here.
            'zone' => [] === $calendars ? 'UTC' : $calendars[0]->getTimezone(),
        ];
    }

    /**
     * The window, as the guest's grid asks for it.
     *
     * Every event comes back `readOnly`, whatever it is. The grids refuse to drag
     * one that says so, which is what makes the page read-only in the browser -
     * and it is not the guarantee: there is no write route to reach. Both, because
     * a control that starts a gesture it cannot finish reads as broken.
     *
     * @param list<array<string, mixed>> $events
     * @param list<array<string, mixed>> $reminders
     *
     * @return array<string, mixed>
     */
    public function windowView(array $events, array $reminders): array
    {
        return [
            'events' => array_map(
                static fn (array $event): array => [...$event, 'readOnly' => true],
                $events,
            ),
            'reminders' => $reminders,
        ];
    }

    /**
     * A calendar, stripped to what a grid draws it with.
     *
     * Not the full serializer's output: that carries the visibility, the owner's
     * name, the share list and whether a feed exists - the calendar's
     * administration, which is none of a guest's business.
     *
     * @return array<string, mixed>
     */
    private function guestCalendar(PlanningInterface $planning): array
    {
        return [
            'id' => $planning->getId(),
            'name' => $planning->getName(),
            'colourSlot' => $planning->getColourSlot(),
            'timezone' => $planning->getTimezone(),
        ];
    }
}
