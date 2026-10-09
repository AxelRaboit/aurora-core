/**
 * One colour per publication status, wherever a status is drawn: the posts
 * list, the editor, the revisions, the search palette and the dashboard.
 *
 * Written once, here. The list, the editor and the revisions each kept their
 * own copy - three that agreed - while the search palette and this file
 * carried a fourth that did not: a draft was grey in the list and amber in the
 * search, a scheduled post sky in one and violet in the other (visual redesign
 * of the suite, 09/10/2026). A draft is grey because nothing is asked of
 * anyone yet; amber is kept for what waits on a reviewer.
 */
export const POST_STATUS_COLORS = Object.freeze({
    draft: "gray",
    pending_review: "amber",
    scheduled: "sky",
    published: "emerald",
    archived: "zinc",
});

const POST_STATUS_CLASSES = {
    draft: "bg-surface-2 text-secondary",
    pending_review: "bg-amber-500/15 text-amber-400",
    scheduled: "bg-sky-500/15 text-sky-400",
    published: "bg-emerald-500/15 text-emerald-400",
    archived: "bg-zinc-500/15 text-zinc-400",
};

export function statusBadge(status) {
    return POST_STATUS_CLASSES[status] ?? "bg-surface-2 text-secondary";
}

export function statusBadgeColor(status) {
    return POST_STATUS_COLORS[status] ?? "gray";
}

/**
 * The same statuses, in the chart palette (`--chart-cat-*`, see chart.css),
 * for `AppShareBar`'s `slot`.
 *
 * The colour belongs to the status, not to its rank, so it is named rather
 * than left to the position: a dashboard bar painted by position showed a
 * published post in yellow, three rows above a list that shows it green. Each
 * status takes the slot nearest its badge - green for emerald, yellow for
 * amber, blue for sky - and the two greys the palette deliberately lacks come
 * from the interface's own neutrals, which `AppShareBar` reads as `neutral`
 * and `muted`.
 *
 * The palette was validated for neighbours in its own order, and these are not
 * neighbours there, so the four joints of the bar (grey, yellow, blue, green,
 * light grey) were measured again under protanopia, deuteranopia and
 * tritanopia, in both modes: the closest pair is blue against green under
 * tritanopia, about twice the separation of aqua against blue, which is why
 * published takes the green slot and not the aqua one (09/10/2026).
 *
 * Comments keep the palette's order: their natural colours are green for
 * approved and red for spam, the one pair colour-blind readers cannot tell
 * apart, and the legend already names each part.
 */
export const POST_STATUS_CHART_SLOTS = Object.freeze({
    draft: "neutral",
    pending_review: 4,
    scheduled: 1,
    published: 6,
    archived: "muted",
});

const ACCESS_REQUEST_STATUS_CLASSES = {
    pending: "bg-amber-500/15 text-amber-400",
    approved: "bg-emerald-500/15 text-emerald-400",
    rejected: "bg-surface-2 text-muted",
};

export function accessRequestStatusBadge(status) {
    return (
        ACCESS_REQUEST_STATUS_CLASSES[status] ?? "bg-surface-2 text-secondary"
    );
}

const ACCESS_REQUEST_STATUS_COLORS = {
    pending: "amber",
    approved: "emerald",
    rejected: "gray",
};

export function accessRequestStatusBadgeColor(status) {
    return ACCESS_REQUEST_STATUS_COLORS[status] ?? "gray";
}

/**
 * One colour per contract status, the same in the list, the contract's own
 * screen and the customer's file. Amber is kept for what waits on the
 * provider, rose for an answer that went the wrong way.
 */
const CONTRACT_STATUS_COLORS = {
    draft: "gray",
    sealed: "accent",
    sent: "sky",
    opened: "violet",
    signed_by_customer: "amber",
    countersigned: "emerald",
    refused: "rose",
    expired: "slate",
    revoked: "slate",
    cancelled: "slate",
};

export function contractStatusColor(status) {
    return CONTRACT_STATUS_COLORS[status] ?? "gray";
}
