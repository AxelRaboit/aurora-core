/**
 * The share block's links: the types on offer, the row a page shows when
 * nobody configured it, and how each becomes an address.
 *
 * Shared by the public buttons and the editor, so the list the editor offers
 * and the list the page can draw cannot drift apart. Mirrors
 * ShareLinksNormalizer, which holds the same rules at the write boundary.
 */

export const SHARE_TYPES = [
    "copy",
    "linkedin",
    "whatsapp",
    "x",
    "facebook",
    "bluesky",
    "email",
    "custom",
];

export const MAX_SHARE_LINKS = 12;

// The row a page shows until someone configures its own.
export const DEFAULT_SHARE_LINKS = [
    { type: "copy", label: null, url: null, color: null },
    { type: "linkedin", label: null, url: null, color: null },
    { type: "facebook", label: null, url: null, color: null },
];

const TEMPLATES = {
    linkedin: "https://www.linkedin.com/sharing/share-offsite/?url={url}",
    whatsapp: "https://wa.me/?text={title}%20{url}",
    x: "https://x.com/intent/post?url={url}&text={title}",
    facebook: "https://www.facebook.com/sharer/sharer.php?u={url}",
    bluesky: "https://bsky.app/intent/compose?text={title}%20{url}",
    email: "mailto:?subject={title}&body={url}",
};

/**
 * The address a link opens, with `{url}` and `{title}` filled in, or null.
 * Only `https://` and `mailto:` survive: the server already refuses anything
 * else, and this keeps a stored value from an older rule out of an `href`.
 */
export function shareHref(link, url, title) {
    const template = "custom" === link.type ? link.url : TEMPLATES[link.type];

    if (!template) {
        return null;
    }

    const href = template
        .replaceAll("{url}", encodeURIComponent(url))
        .replaceAll("{title}", encodeURIComponent(title));

    return /^(https:\/\/|mailto:)/i.test(href) ? href : null;
}

/**
 * The links a page draws: its own when configured (an empty list included,
 * which draws nothing), the default row otherwise. Links that resolve to no
 * address are dropped; copying needs none.
 */
export function resolveShareLinks(links, url, title) {
    return (Array.isArray(links) ? links : DEFAULT_SHARE_LINKS)
        .filter((link) => SHARE_TYPES.includes(link.type))
        .map((link) => ({
            ...link,
            href: "copy" === link.type ? null : shareHref(link, url, title),
        }))
        .filter((link) => "copy" === link.type || null !== link.href);
}
