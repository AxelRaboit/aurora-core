/**
 * How many lines a slide can bring in one by one.
 *
 * **Counted from the content, never from the page.** The lines live in a list
 * slot, so their number is known before anything is drawn; asking the DOM
 * would tie the way a deck is driven to the way it happens to be laid out
 * that day, and would answer differently on a thumbnail.
 *
 * **In its own file because two windows use it.** The player projects and the
 * presenter drives, and both must count the same: a copy in each is the day
 * one of them gains a template and the two windows stop agreeing on how many
 * key presses the slide needs.
 */
const REVEAL_SLOTS = ["bullets", "items", "steps", "figures", "lines"];

/** Zero for any slide that asked for nothing, so for all the old ones. */
export function revealableIn(slide) {
    const content = slide?.content;

    // A free slide counts its presses on its elements: each one says on which
    // press it enters, and the slide needs as many as the latest one. Two
    // elements on the same press enter together.
    if (slide?.layout === "free") {
        const ranks = (content?.elements ?? []).map(
            (element) => element?.reveal ?? 0,
        );

        return ranks.length ? Math.max(0, ...ranks) : 0;
    }

    if (content?.reveal !== true) return 0;

    const slot = REVEAL_SLOTS.find((name) => Array.isArray(content[name]));

    return slot ? content[slot].length : 0;
}
