/**
 * How a follow-up is drawn, wherever it is: the board's cards, the list's
 * column, a customer's page.
 *
 * The state comes from the server (`followUpState`), which reads "today" in
 * the site's timezone; the page only picks a colour for it.
 */

/** Late is the one that must jump out; today is a reminder; later is just a date. */
export function followUpTone(state) {
    switch (state) {
        case "late":
            return "bg-danger-soft text-danger";
        case "today":
            return "bg-warning-soft text-warning";
        default:
            return "bg-surface-2 text-secondary";
    }
}

/**
 * A calendar day ("2026-10-14") as something the date formatter reads as that
 * day everywhere: at noon, so no timezone on earth turns it into the day
 * before.
 */
export function dayOf(date) {
    return date ? `${date}T12:00:00` : null;
}

/** Whether a follow-up asks for attention now. */
export function isFollowUpDue(customer) {
    return (
        "late" === customer.followUpState || "today" === customer.followUpState
    );
}
