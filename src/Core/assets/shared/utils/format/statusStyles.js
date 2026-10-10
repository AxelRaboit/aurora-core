/**
 * One colour per publication status, wherever a status is drawn: the posts
 * list, the editor, the revisions, the search palette and the dashboard.
 *
 * Written once, here. The list, the editor and the revisions each kept their
 * own copy while the search palette carried a fourth that disagreed (visual
 * redesign of the suite, 09/10/2026).
 *
 * The palette is the validated mockup's (10/10/2026): a published post is in
 * ardoise, the text colour itself - it is the settled state, the one that
 * needs nothing - and a draft in amber, because a draft is what is left to
 * do. Waiting for a reviewer is sky, dated for later is emerald (it will go
 * out on its own), archived is grey.
 */
export const POST_STATUS_COLORS = Object.freeze({
    draft: "amber",
    pending_review: "sky",
    scheduled: "emerald",
    published: "ink",
    archived: "gray",
});

const POST_STATUS_CLASSES = {
    draft: "bg-amber-500/15 text-amber-400",
    pending_review: "bg-sky-500/15 text-sky-400",
    scheduled: "bg-emerald-500/15 text-emerald-400",
    published: "bg-primary/10 text-primary",
    archived: "bg-surface-2 text-secondary",
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
 * than left to the position: each status takes the slot nearest its badge -
 * yellow for amber, blue for sky, aqua for emerald - and the two the palette
 * deliberately lacks come from the interface itself: `ink` (the text colour)
 * for published, `muted` (the light grey) for archived.
 *
 * The palette was validated for neighbours in its own order, and these are not
 * neighbours there, so the four joints of the bar were measured again under
 * protanopia, deuteranopia and tritanopia, in both modes (10/10/2026). Violet
 * for "scheduled" was tried first and refused: against the blue of "waiting"
 * it all but vanished for a protanope in the dark theme. Aqua holds.
 */
export const POST_STATUS_CHART_SLOTS = Object.freeze({
    draft: 4,
    pending_review: 1,
    scheduled: 3,
    published: "ink",
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
