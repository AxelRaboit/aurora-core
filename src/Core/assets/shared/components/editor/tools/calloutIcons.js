/**
 * The icons a callout can carry, as the inside of a 24x24 stroked SVG.
 *
 * Mirrors `BlocksRenderer::CALLOUT_ICONS`: same names, same order, same
 * drawings. The editor only stores the name; the page is drawn from the
 * server's list, so a name missing there shows no icon rather than a broken
 * one.
 */
export const CALLOUT_ICONS = [
    {
        value: "info",
        svg: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
    },
    {
        value: "check-circle",
        svg: '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
    },
    {
        value: "alert-triangle",
        svg: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    },
    {
        value: "clock",
        svg: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    },
    {
        value: "calendar",
        svg: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
    },
    {
        value: "star",
        svg: '<path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/>',
    },
    {
        value: "lightbulb",
        svg: '<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>',
    },
    {
        value: "message-circle",
        svg: '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
    },
    {
        value: "pause-circle",
        svg: '<circle cx="12" cy="12" r="10"/><path d="M10 15V9"/><path d="M14 15V9"/>',
    },
];

/** Wraps one icon's drawing in the SVG both the picker and the preview use. */
export function calloutIconSvg(value) {
    const icon = CALLOUT_ICONS.find((entry) => entry.value === value);
    if (!icon) return "";

    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${icon.svg}</svg>`;
}
