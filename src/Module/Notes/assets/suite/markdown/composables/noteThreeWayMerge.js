import { lineDiff } from "./noteLineDiff.js";

/**
 * Putting two people's edits back together instead of asking who wins.
 *
 * **The question this answers.** Two people write in the same note; the second
 * save starts from a version the first has already replaced. Until now the
 * screen asked - take their version, or keep mine - and either answer throws
 * somebody's paragraph away. But in the ordinary case the two of them were
 * writing in *different places*, and nothing had to be thrown away at all.
 *
 * Three texts are needed, and the editor has all three: the `base` the form
 * loaded, `mine` on screen, and `theirs` as the server now has it. Classic
 * diff3: both sides' edits are expressed as replacements of base ranges, and
 * applied in order. Where the two touch the same lines - and only there - the
 * merge refuses and the person decides after all.
 *
 * **It never guesses.** A refusal returns `null`, which is the editor's cue to
 * ask. That matters more than merging often: a merge that silently picks a
 * side is worse than the question it replaced.
 */

/**
 * One side's edits, as replacements of ranges of `base`.
 *
 * `{start, end, lines}` reads "base lines [start, end) become these lines" -
 * so a pure insertion has `start === end`, and a pure deletion has no lines.
 * Built from {@see lineDiff}, which is already the module's longest common
 * subsequence and already tested; its output interleaves removals and
 * additions inside a changed run, which is why a run is accumulated whole
 * rather than read op by op.
 *
 * @returns {Array<{start: number, end: number, lines: string[]}>|null} null when the texts are too large to diff
 */
function hunksBetween(base, other) {
    const difference = lineDiff(base, other);
    if (null === difference) return null;

    const hunks = [];
    let at = 0;
    let index = 0;

    while (index < difference.length) {
        if ("same" === difference[index].kind) {
            at += 1;
            index += 1;

            continue;
        }

        const start = at;
        const lines = [];

        while (index < difference.length && "same" !== difference[index].kind) {
            if ("removed" === difference[index].kind) at += 1;
            else lines.push(difference[index].text);
            index += 1;
        }

        hunks.push({ start, end: at, lines });
    }

    return hunks;
}

/** Whether two edits cannot both be applied. */
function clash(ours, theirs) {
    // They rewrote lines the other also rewrote.
    if (ours.start < theirs.end && theirs.start < ours.end) return true;

    // Two insertions at the same point: nothing in either text says which of
    // them goes first, so there is nothing to be right about.
    return (
        ours.start === ours.end &&
        theirs.start === theirs.end &&
        ours.start === theirs.start
    );
}

/** Whether two edits are the same edit - both people typed it. */
function identical(ours, theirs) {
    return (
        ours.start === theirs.start &&
        ours.end === theirs.end &&
        ours.lines.length === theirs.lines.length &&
        ours.lines.every((line, index) => line === theirs.lines[index])
    );
}

/**
 * The two texts put back together, or `null` when they cannot be.
 *
 * @param {string} base   what the form loaded
 * @param {string} mine   what is on screen
 * @param {string} theirs what the server has now
 * @returns {string|null}
 */
export function threeWayMerge(base, mine, theirs) {
    // The three cheap answers, and they cover most of real life: nobody
    // disagreed, or only one of us touched it.
    if (mine === theirs) return mine;
    if (base === mine) return theirs;
    if (base === theirs) return mine;

    const baseLines = String(base ?? "").split("\n");
    const ourHunks = hunksBetween(base, mine);
    const theirHunks = hunksBetween(base, theirs);

    if (null === ourHunks || null === theirHunks) return null;

    const merged = [];
    let at = 0;
    let ourIndex = 0;
    let theirIndex = 0;

    const take = (hunk) => {
        while (at < hunk.start) merged.push(baseLines[at++]);
        merged.push(...hunk.lines);
        at = hunk.end;
    };

    while (ourIndex < ourHunks.length || theirIndex < theirHunks.length) {
        const ourHunk = ourHunks[ourIndex] ?? null;
        const theirHunk = theirHunks[theirIndex] ?? null;

        if (null === theirHunk) {
            take(ourHunk);
            ourIndex += 1;

            continue;
        }

        if (null === ourHunk) {
            take(theirHunk);
            theirIndex += 1;

            continue;
        }

        if (clash(ourHunk, theirHunk)) {
            if (!identical(ourHunk, theirHunk)) return null;

            take(ourHunk);
            ourIndex += 1;
            theirIndex += 1;

            continue;
        }

        // Whichever comes first in the base. They cannot overlap at this
        // point, so taking the earlier one never has to be undone.
        if (ourHunk.start <= theirHunk.start) {
            take(ourHunk);
            ourIndex += 1;
        } else {
            take(theirHunk);
            theirIndex += 1;
        }
    }

    while (at < baseLines.length) merged.push(baseLines[at++]);

    return merged.join("\n");
}

/**
 * A single value three ways: a banner address, an appearance, a title.
 *
 * No merging to do - a value is replaced, not edited - so the only question
 * is whether exactly one of us replaced it. Both did, differently: the person
 * decides.
 *
 * @returns {{value: unknown}|null} wrapped, so a legitimately null value is
 *                                  not read as a refusal
 */
export function threeWayValue(base, mine, theirs) {
    if (mine === theirs) return { value: mine };
    if (base === mine) return { value: theirs };
    if (base === theirs) return { value: mine };

    return null;
}

/**
 * Tags three ways, as the set they are.
 *
 * Start from the base, add what either of us added, remove what either of us
 * removed. There is no conflict to find here: "we both added a tag" is two
 * tags, not a disagreement, and that is the whole difference between a set and
 * a text.
 *
 * @param {string[]} base
 * @param {string[]} mine
 * @param {string[]} theirs
 * @returns {string[]}
 */
export function threeWayTags(base, mine, theirs) {
    const baseSet = new Set(base ?? []);
    const ourSet = new Set(mine ?? []);
    const theirSet = new Set(theirs ?? []);

    const merged = new Set(baseSet);

    for (const tag of ourSet) if (!baseSet.has(tag)) merged.add(tag);
    for (const tag of theirSet) if (!baseSet.has(tag)) merged.add(tag);
    for (const tag of baseSet) {
        if (!ourSet.has(tag) || !theirSet.has(tag)) merged.delete(tag);
    }

    // The order somebody sees on screen: ours first, then what arrived.
    return [...(mine ?? []), ...(theirs ?? [])].filter(
        (tag, index, all) => merged.has(tag) && all.indexOf(tag) === index,
    );
}
