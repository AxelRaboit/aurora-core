import { RATIO } from "./model.js";

/**
 * Where a moving box catches: the slide's edges and middle, and the edges and
 * middles of everything else on it.
 *
 * **Measured in per cent of the slide, with one threshold seen by the eye.**
 * A threshold of 0.8 is 0.8% of the width horizontally; vertically the same
 * distance on screen is 0.8 / RATIO per cent of the height, because the slide
 * is shorter than it is wide. A box therefore catches at the same distance in
 * both directions, which is what a hand dragging it feels.
 *
 * Pure functions, so the arithmetic is tested without a pointer.
 */
export const THRESHOLD = 0.8;

/** The lines a box may catch on, per axis. */
export function targets(others) {
    const vertical = [0, 50, 100];
    const horizontal = [0, 50, 100];

    for (const box of others) {
        vertical.push(box.left, box.centreX, box.right);
        horizontal.push(box.top, box.centreY, box.bottom);
    }

    return { vertical, horizontal };
}

/** The nearest line to any of the points, within reach, or null. */
function nearest(points, lines, reach) {
    let best = null;

    for (const point of points) {
        for (const line of lines) {
            const distance = line - point;

            if (
                Math.abs(distance) <= reach &&
                (best === null || Math.abs(distance) < Math.abs(best.shift))
            ) {
                best = { shift: distance, at: line };
            }
        }
    }

    return best;
}

/**
 * How far to nudge a moving box so it catches, and the guides to draw.
 *
 * @param {{left: number, right: number, top: number, bottom: number, centreX: number, centreY: number}} box
 * @param {Array} others the boxes of what is not moving
 * @returns {{dx: number, dy: number, guides: Array<{axis: string, at: number}>}}
 */
export function snapMove(box, others, threshold = THRESHOLD) {
    const { vertical, horizontal } = targets(others);
    const guides = [];

    const x = nearest([box.left, box.centreX, box.right], vertical, threshold);
    const y = nearest(
        [box.top, box.centreY, box.bottom],
        horizontal,
        threshold / RATIO,
    );

    if (x) guides.push({ axis: "x", at: x.at });
    if (y) guides.push({ axis: "y", at: y.at });

    return { dx: x?.shift ?? 0, dy: y?.shift ?? 0, guides };
}

/**
 * The same, for the edges a handle is dragging: only those move, so only
 * those catch.
 *
 * @param {{left?: number, right?: number, top?: number, bottom?: number}} edges
 */
export function snapEdges(edges, others, threshold = THRESHOLD) {
    const { vertical, horizontal } = targets(others);
    const result = { ...edges };
    const guides = [];

    for (const side of ["left", "right"]) {
        if (edges[side] == null) continue;

        const hit = nearest([edges[side]], vertical, threshold);

        if (hit) {
            result[side] = hit.at;
            guides.push({ axis: "x", at: hit.at });
        }
    }

    for (const side of ["top", "bottom"]) {
        if (edges[side] == null) continue;

        const hit = nearest([edges[side]], horizontal, threshold / RATIO);

        if (hit) {
            result[side] = hit.at;
            guides.push({ axis: "y", at: hit.at });
        }
    }

    return { edges: result, guides };
}

/**
 * An angle caught on the quarter turns, and on fifteen-degree steps when the
 * person holds Shift.
 */
export function snapAngle(angle, fine = false) {
    const folded = (((angle % 360) + 540) % 360) - 180;

    if (fine) return Math.round(folded / 15) * 15;

    const quarter = Math.round(folded / 45) * 45;

    return Math.abs(quarter - folded) <= 4
        ? quarter
        : Math.round(folded * 10) / 10;
}
