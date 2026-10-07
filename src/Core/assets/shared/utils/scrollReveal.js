/**
 * The arrival of a grid zone when the reader reaches it - `[data-reveal]`.
 *
 * **The page reads without this file**, and that constraint decides its
 * shape. Nothing is hidden by the stylesheet: the starting state is set here,
 * zone by zone, through the `aurora-reveal-armed` class. Script in error,
 * JavaScript turned off, browser without IntersectionObserver: the class is
 * never set and the content shows. Hiding first to show again afterwards is
 * the classic way to lose a whole page on a loading error.
 *
 * **And a zone already on screen is never armed.** The bundle weighs several
 * hundred kilobytes: on a slow connection the page is painted well before it
 * runs, and arming at that moment would make what the reader is already
 * looking at disappear and come back. It is also the right rule in itself -
 * animating the entrance of what has been in view from the start announces
 * nothing.
 *
 * The measurements all happen before the writes. Reading a position, then
 * writing a class, then reading the next one forces the browser to recompute
 * the layout on every turn, which on a long page is thirty times.
 *
 * One observer for the whole page, not one per zone, and **a zone arrives
 * only once**: replaying the effect when scrolling back up gives a page that
 * flickers while someone looks for a paragraph already read, exactly the
 * moment when nothing should move.
 *
 * Markup: templates/Frontend/themes/default/editorial/post/_grid.html.twig
 */
const SELECTOR = "[data-reveal]";

/** What hides a zone while it waits for its turn. Set here, never by the HTML. */
const ARMED = "aurora-reveal-armed";

/** The class a zone carries once it has arrived. */
const REVEALED = "aurora-revealed";

/**
 * Set on `<html>` while a zone waits for its turn.
 *
 * A zone that comes from the right is shifted out of its box, which makes
 * the page longer and gives it a horizontal scrollbar - measured in
 * production, twenty-four pixels, exactly the shift. The stylesheet clips
 * that overflow under this attribute, and it goes away with the last zone:
 * restraining the page permanently for a movement that lasts seven tenths of
 * a second would be paying too much.
 */
const RUNNING = "data-reveal-running";

/**
 * The slightest pixel is enough: the bottom margin decides the moment, by
 * firing when the top of the zone crosses 92% of the window.
 *
 * A threshold as a fraction of the zone does not hold for a large block. It
 * was 0.08 until 25/09/2026, and on a phone the list of the twenty-four
 * topics of the Aurora tour, stacked in one column, was nearly 10,000 px:
 * the window never shows more than 7% of it, so the zone stayed armed,
 * invisible, while keeping its space. On a computer the three columns made
 * it three times shorter, and the defect did not show.
 */
const THRESHOLD = 0;
const MARGIN = "0px 0px -8% 0px";

/**
 * The delay between two arrivals of the same group.
 *
 * A gallery of twenty photos crosses the threshold at once, and twenty zones
 * that appear together do not read as twenty: they read as one block that
 * changes opacity. Seventy milliseconds are enough for the eye to follow the
 * series without the last one keeping it waiting.
 *
 * **The delay is computed at the moment of arrival, not when the HTML is
 * written.** A rank engraved in the markup would penalise the twentieth
 * photo even when it is reached alone, at the bottom of the page, a quarter
 * of an hour later: it would wait 1.4 seconds for nothing. Here, what arrives
 * together is staggered, and what arrives alone does not wait.
 */
const STAGGER = 70;

/** Beyond this, it no longer reads as a cascade, it reads as waiting for the end. */
const STAGGER_MAX = 8;

/**
 * The order in which a batch of arrivals plays.
 *
 * Pulled out of the observer's closure to be testable: it is the only logic
 * in this file that decides something, and a scroll-driven effect cannot be
 * tested in a browser without watching it.
 *
 * Top to bottom, then left to right, because **the browser promises nothing
 * about the order of the entries in a batch** and a cascade that starts from
 * the bottom or the middle is noticed at once.
 *
 * @param {Array<{isIntersecting: boolean, target: Element}>} entries
 *
 * @returns {Array<Element>} what arrives, in the order the eye takes it in
 */
export function cascadeOrder(entries) {
    return entries
        .filter((entry) => entry.isIntersecting)
        .map((entry) => ({
            target: entry.target,
            box: entry.target.getBoundingClientRect(),
        }))
        .sort(
            (left, right) =>
                left.box.top - right.box.top || left.box.left - right.box.left,
        )
        .map(({ target }) => target);
}

/**
 * The delay of an arrival according to its rank in the batch, in milliseconds.
 *
 * Capped: beyond eight steps it no longer reads as a cascade, it reads as
 * waiting for the end. A gallery of forty photos crossing the threshold
 * together would otherwise spread over nearly three seconds.
 */
export function cascadeDelay(rank) {
    return Math.min(Math.max(rank, 0), STAGGER_MAX) * STAGGER;
}

function reveal(element, remaining, rank = 0) {
    // Written inline rather than in CSS: the rank is only known here.
    if (rank > 0) {
        element.style.transitionDelay = `${cascadeDelay(rank)}ms`;
    }

    element.classList.add(REVEALED);

    // Released once the zone has landed: `will-change` left on thirty zones
    // reserves memory for a movement that will not happen again.
    // Only the zone's own transition ends the arrival: the cards inside it
    // run theirs too, and `transitionend` bubbles - the first card to land
    // would otherwise disarm the zone while it was still moving.
    const done = (event) => {
        if (event.target !== element) {
            return;
        }

        element.removeEventListener("transitionend", done);
        element.classList.remove(ARMED, REVEALED);
        element.style.transitionDelay = "";
        remaining.delete(element);
        if (0 === remaining.size) {
            document.documentElement.removeAttribute(RUNNING);
        }
    };
    element.addEventListener("transitionend", done);
}

function arm() {
    const zones = Array.from(document.querySelectorAll(SELECTOR));

    if (0 === zones.length) {
        return;
    }

    // The system setting first: we touch nothing.
    if (window.matchMedia?.("(prefers-reduced-motion: reduce)").matches) {
        return;
    }

    // Without an observer, everything stays shown rather than arming what
    // nothing would come to disarm.
    if (!("IntersectionObserver" in window)) {
        return;
    }

    // All the reads, then all the writes.
    const below = zones.filter(
        (zone) => zone.getBoundingClientRect().top >= window.innerHeight,
    );

    if (0 === below.length) {
        return;
    }

    below.forEach((zone) => zone.classList.add(ARMED));
    document.documentElement.setAttribute(RUNNING, "");

    const remaining = new Set(below);

    const observer = new IntersectionObserver(
        (entries) => {
            cascadeOrder(entries).forEach((target, rank) => {
                reveal(target, remaining, rank);
                observer.unobserve(target);
            });
        },
        { threshold: THRESHOLD, rootMargin: MARGIN },
    );

    below.forEach((zone) => observer.observe(zone));

    // **A page that grows longer afterwards must not leave hidden content in
    // view.** The positions are measured when the document loads, before the
    // images settle: an image without dimensions takes up zero pixels, so the
    // page is shorter than it will be, and a zone judged "below the window" can
    // end up inside it a second later without anything crossing it again - the
    // observer does not fire again for an element that has not moved relative
    // to the window.
    //
    // The cause is fixed elsewhere, by writing `width` and `height` on the
    // images. This is the safety net: on `load`, everything armed and now
    // visible arrives, and the worst case becomes a zone that appears without
    // the effect rather than a zone that does not appear.
    window.addEventListener(
        "load",
        () => {
            remaining.forEach((zone) => {
                if (zone.getBoundingClientRect().top >= window.innerHeight) {
                    return;
                }

                reveal(zone, remaining);
                observer.unobserve(zone);
            });
        },
        { once: true },
    );
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", arm);
} else {
    arm();
}
