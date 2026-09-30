const POST_STATUS_CLASSES = {
    published: "bg-emerald-500/15 text-emerald-400",
    draft: "bg-amber-500/15 text-amber-400",
    pending_review: "bg-sky-500/15 text-sky-400",
    scheduled: "bg-violet-500/15 text-violet-400",
    archived: "bg-slate-500/15 text-slate-400",
};

export function statusBadge(status) {
    return POST_STATUS_CLASSES[status] ?? "bg-surface-2 text-secondary";
}

const POST_STATUS_COLORS = {
    published: "emerald",
    draft: "amber",
    pending_review: "sky",
    scheduled: "violet",
    archived: "slate",
};

export function statusBadgeColor(status) {
    return POST_STATUS_COLORS[status] ?? "gray";
}

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
