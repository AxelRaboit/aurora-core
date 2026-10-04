/**
 * The networks a social list knows, as the inside of a 24x24 stroked SVG.
 *
 * Mirrors `BlocksRenderer::SOCIAL_ICONS`: same names, same order, same
 * drawings. The editor stores the name only; the page is drawn from the
 * server's list, so a name missing there is a row the page leaves out.
 */
export const SOCIAL_NETWORKS = [
    {
        value: "instagram",
        label: "Instagram",
        svg: '<rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" x2="17.51" y1="6.5" y2="6.5"/>',
    },
    {
        value: "facebook",
        label: "Facebook",
        svg: '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>',
    },
    {
        value: "linkedin",
        label: "LinkedIn",
        svg: '<path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect width="4" height="12" x="2" y="9"/><circle cx="4" cy="4" r="2"/>',
    },
    {
        value: "tiktok",
        label: "TikTok",
        svg: '<path d="M9 12a4 4 0 1 0 4 4V3c.5 2.7 2.6 4.6 5 5"/>',
    },
    {
        value: "youtube",
        label: "YouTube",
        svg: '<path d="M2.5 17a24.12 24.12 0 0 1 0-10 2 2 0 0 1 1.4-1.4 49.56 49.56 0 0 1 16.2 0A2 2 0 0 1 21.5 7a24.12 24.12 0 0 1 0 10 2 2 0 0 1-1.4 1.4 49.55 49.55 0 0 1-16.2 0A2 2 0 0 1 2.5 17"/><path d="m10 15 5-3-5-3z"/>',
    },
    {
        value: "x",
        label: "X",
        svg: '<path d="M4 4l16 16"/><path d="M20 4 4 20"/>',
    },
    {
        value: "website",
        label: "Site",
        svg: '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
    },
];

/** The full SVG of a network's mark, or "" for a name the list does not know. */
export function socialNetworkSvg(value) {
    const network = SOCIAL_NETWORKS.find((entry) => entry.value === value);
    if (!network) return "";

    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${network.svg}</svg>`;
}
