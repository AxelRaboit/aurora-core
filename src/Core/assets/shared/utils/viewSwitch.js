/**
 * Switch a deliverable from the presentation to the page, and back, at the
 * same place.
 *
 * The two views share the address of the sections, `#diapo-N`: slide N in
 * the presentation, the first zone of section N in the page. The
 * `[data-view-switch]` links lead to `?view=page` or `?view=slides`; without
 * a script, they open the other view at the top of the document, and that is
 * still correct.
 *
 * - From the presentation, the address already carries the displayed slide
 *   (`slides.js` keeps it up to date): it is kept.
 * - From the page, the section is the one whose start has passed under the
 *   top of the screen, the last one that has.
 * - When arriving on the page with `#diapo-N`, if the author put their own
 *   anchor on the zone (the identifier is then not `diapo-N`), it is found by
 *   its number.
 */

const SECTION = /^#diapo-(\d+)$/;

/** The section where the reader is in the page, or null at the top of the document. */
export function currentSection(root = document, offset = 120) {
    let current = null;

    for (const element of root.querySelectorAll("[data-section]")) {
        const rect = element.getBoundingClientRect();
        // A zone hidden at this width ("hide on phone") has no size and claims to
        // be at the top of the screen: it does not say where the reader is.
        if (0 === rect.width && 0 === rect.height) continue;
        if (rect.top <= offset) {
            current = Number(element.dataset.section);
        }
    }

    return current;
}

/** The address of the other view, at the same place. */
export function switchHref(
    target,
    { hash = window.location.hash, root = document } = {},
) {
    if ("page" === target) {
        return `?view=page${SECTION.test(hash) ? hash : ""}`;
    }

    const section = currentSection(root);

    return `?view=slides${null !== section ? `#diapo-${section}` : ""}`;
}

/** Arrived on the page on a `#diapo-N` whose zone carries the author's anchor. */
export function revealSection(hash = window.location.hash, root = document) {
    const match = SECTION.exec(hash ?? "");
    if (!match || root.getElementById?.(`diapo-${match[1]}`)) return;

    root.querySelector(`[data-section="${match[1]}"]`)?.scrollIntoView({
        block: "start",
    });
}

function init() {
    document.querySelectorAll("[data-view-switch]").forEach((link) => {
        link.addEventListener("click", () => {
            link.setAttribute("href", switchHref(link.dataset.viewSwitch));
        });
    });

    if (document.querySelector("[data-section]")) revealSection();
}

if ("undefined" !== typeof document) {
    if ("loading" === document.readyState) {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
}
