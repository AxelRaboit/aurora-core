/**
 * The handle of a before/after zone - `<div data-compare>`.
 *
 * The markup arrives as two pictures side by side, and that is deliberate:
 * without this script, or before it runs, a reader still gets a comparison
 * rather than a broken control. What happens here is an upgrade, not the
 * feature - everything this removes was working.
 *
 * The handle is an `<input type="range">` and not a div with pointer
 * listeners, which is the whole accessibility argument in one choice: a range
 * is reachable by Tab, moves on the arrow keys, jumps on Home and End, and
 * announces itself and its value without anything written here. A hand-rolled
 * handle would have needed every one of those written back, and this zone was
 * proposed in a project whose layout controls had to be reworked for exactly
 * that reason.
 *
 * The position travels as a custom property so the clipping is CSS's business
 * rather than a style rewritten on every pointer move.
 *
 * Markup: templates/Frontend/themes/default/editorial/post/_grid_zone.html.twig
 */
const SELECTOR = "[data-compare]";

/** Where the handle sits before anybody touches it. Half is the honest start. */
const START = 50;

function upgrade(root) {
    const before = root.querySelector("[data-compare-before]");
    const after = root.querySelector("[data-compare-after]");

    // A zone whose pair did not resolve is left exactly as it is.
    if (!before || !after) {
        return;
    }

    root.classList.remove("grid", "grid-cols-2", "gap-3");
    root.classList.add(
        "relative",
        "overflow-hidden",
        "rounded-lg",
        "select-none",
    );
    root.style.setProperty("--compare-pos", `${START}%`);

    // The "after" picture is laid over the "before" one and revealed by a clip
    // path. Stacking rather than resizing is what keeps the two aligned: both
    // are drawn at the same size, so a feature at one point of the photograph
    // is at the same point of both.
    after.classList.add("absolute", "inset-0", "m-0");
    after.style.clipPath = "inset(0 0 0 var(--compare-pos))";

    const image = after.querySelector("img");
    if (image) {
        image.classList.add("h-full", "object-cover");
    }

    // The captions are what a screen reader uses to tell the two apart, so
    // they are hidden from sight and not from the accessibility tree.
    root.querySelectorAll("figcaption").forEach((caption) => {
        caption.classList.add("sr-only");
    });

    const handle = document.createElement("input");
    handle.type = "range";
    handle.min = "0";
    handle.max = "100";
    handle.value = String(START);
    handle.setAttribute("aria-label", root.dataset.compareLabel ?? "");
    handle.className =
        "absolute inset-0 h-full w-full cursor-ew-resize appearance-none bg-transparent";

    handle.addEventListener("input", () => {
        root.style.setProperty("--compare-pos", `${handle.value}%`);
    });

    root.appendChild(handle);
}

function arm() {
    document.querySelectorAll(SELECTOR).forEach(upgrade);
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", arm);
} else {
    arm();
}
