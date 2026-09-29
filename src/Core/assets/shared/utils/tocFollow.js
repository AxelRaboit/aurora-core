/**
 * The summary that follows the reading.
 *
 * A summary zone with « Suivre la lecture » renders a second, hidden copy of
 * its list (`[data-toc-follow]`). Once the summary itself has scrolled above
 * the window, that copy slides in under the site's header and says which
 * section is being read, how far along the page is, and opens onto the whole
 * list. It leaves again when the summary comes back into view.
 *
 * The section being read is also marked in the summary on the page
 * (`aria-current` on its link), so a reader who scrolls back up finds it
 * where they left it.
 *
 * Nothing here is needed to read the page: without the script the copy stays
 * `hidden`, and the summary above works as plain links.
 */

const SELECTOR = "[data-toc-follow]";
const LINK = "[data-toc-link]";

/** Room below the header where a heading counts as "being read". */
const READING_LINE = 120;

/**
 * The heading being read: the last one whose top has passed the reading
 * line, or null above the first.
 *
 * @param {Array<{top: number}>} tops headings' tops, in page order
 */
export function currentIndex(tops, line) {
    let current = -1;
    tops.forEach((top, index) => {
        if (top <= line) {
            current = index;
        }
    });

    return current;
}

/** Progress through the page, from 0 to 1. */
export function progress(scrolled, scrollable) {
    if (scrollable <= 0) {
        return 1;
    }

    return Math.min(1, Math.max(0, scrolled / scrollable));
}

/**
 * The number shown before the section's name: 01, 02, and 04.1 for a
 * sub-heading under the fourth - the same numbers as the numbered index.
 *
 * @param {number[]} levels every heading's level, in page order
 */
export function numberOf(levels, index) {
    let major = 0;
    let minor = 0;
    let label = "";
    levels.slice(0, index + 1).forEach((level) => {
        if (2 === level) {
            major += 1;
            minor = 0;
            label = String(major).padStart(2, "0");
        } else {
            minor += 1;
            label = `${String(Math.max(major, 1)).padStart(2, "0")}.${minor}`;
        }
    });

    return label;
}

function follow(bar) {
    const nav = document.getElementById(bar.dataset.tocFollow);
    if (!nav) {
        return;
    }

    const barLinks = Array.from(bar.querySelectorAll(LINK));
    const headings = barLinks.map((link) =>
        document.getElementById(decodeURIComponent(link.hash.slice(1))),
    );
    if (headings.some((heading) => !heading)) {
        return;
    }

    const levels = headings.map((heading) =>
        "H3" === heading.tagName ? 3 : 2,
    );
    const allLinks = [...Array.from(nav.querySelectorAll(LINK)), ...barLinks];
    const details = bar.querySelector("details");
    const current = bar.querySelector("[data-toc-current]");
    const number = bar.querySelector("[data-toc-number]");
    const header = document.querySelector("body > header, header.sticky");

    // Out of the zone it was written in: a zone arriving on scroll is
    // translated for a moment, and a translated ancestor turns `fixed` into
    // "fixed to that zone". Kept inside the post's accent, so the bar wears
    // the page's colour.
    (nav.closest(".aurora-post-accent") ?? document.body).appendChild(bar);
    bar.hidden = false;

    let shown = null;
    let active = null;
    let queued = false;

    const update = () => {
        queued = false;
        const top = header
            ? Math.max(0, header.getBoundingClientRect().bottom)
            : 0;
        bar.style.setProperty("--aurora-toc-top", `${top}px`);

        const navGone = nav.getBoundingClientRect().bottom < top;
        const index = currentIndex(
            headings.map((heading) => heading.getBoundingClientRect().top),
            top + READING_LINE,
        );

        const visible = navGone && index >= 0;
        if (visible !== shown) {
            shown = visible;
            bar.toggleAttribute("data-visible", visible);
            if (!visible) {
                details.open = false;
            }
        }

        if (index !== active) {
            active = index;
            const id = index >= 0 ? headings[index].id : null;
            allLinks.forEach((link) => {
                if (id && link.hash === `#${id}`) {
                    link.setAttribute("aria-current", "location");
                } else {
                    link.removeAttribute("aria-current");
                }
            });
            // The words only: the link also carries its number, which the
            // bar shows in a span of its own.
            const words =
                index >= 0
                    ? (barLinks[index].querySelector("span:last-child") ??
                      barLinks[index])
                    : null;
            current.textContent = words ? words.textContent.trim() : "";
            number.textContent = index >= 0 ? numberOf(levels, index) : "";
        }

        const root = document.documentElement;
        bar.style.setProperty(
            "--aurora-toc-progress",
            String(
                progress(
                    window.scrollY,
                    root.scrollHeight - window.innerHeight,
                ),
            ),
        );
    };

    const queue = () => {
        if (!queued) {
            queued = true;
            window.requestAnimationFrame(update);
        }
    };

    window.addEventListener("scroll", queue, { passive: true });
    window.addEventListener("resize", queue);
    // A section picked from the list closes it: the reader chose, and the
    // open list would cover the section they asked for.
    barLinks.forEach((link) =>
        link.addEventListener("click", () => {
            details.open = false;
        }),
    );
    update();
}

function init() {
    document.querySelectorAll(SELECTOR).forEach(follow);
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", init);
} else {
    init();
}
