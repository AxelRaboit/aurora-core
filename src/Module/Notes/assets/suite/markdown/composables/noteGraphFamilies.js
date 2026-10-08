/**
 * The graph's families, as pure functions: which notes one space keeps, and
 * the colour a note is drawn with. Kept out of `useNoteGraph` so they can be
 * tested without a canvas.
 */

/** The colour of a note that neither its folders nor its space colour. */
export const DEFAULT_NODE_COLOR = "#818cf8";

/**
 * The colour a node is filled with: its family's, or the default one.
 *
 * The server sends `#rrggbb` or nothing; anything else (an older payload, a
 * value typed by hand in the database) falls back to the default rather than
 * leaving the canvas with an invalid fill, which it would silently ignore.
 */
export function nodeColor(node) {
    const color = node?.color;

    return typeof color === "string" && /^#[0-9a-f]{6}$/i.test(color)
        ? color
        : DEFAULT_NODE_COLOR;
}

/**
 * The graph restricted to one space: its notes, and the links between them.
 *
 * A link that leaves the space is dropped, otherwise the canvas would draw a
 * line towards a note it does not show. An empty or missing space id keeps
 * everything.
 *
 * @template {{id: number|string, spaceId?: number|string}} GraphNode
 * @param {GraphNode[]} nodes
 * @param {{source: number|string, target: number|string}[]} edges
 * @param {number|string|null} spaceId
 * @returns {{nodes: GraphNode[], edges: {source: number|string, target: number|string}[]}}
 */
export function filterGraphBySpace(nodes, edges, spaceId) {
    const allNodes = nodes ?? [];
    const allEdges = edges ?? [];
    if (spaceId === null || spaceId === undefined || spaceId === "") {
        return { nodes: allNodes, edges: allEdges };
    }

    const kept = allNodes.filter(
        (node) => String(node.spaceId) === String(spaceId),
    );
    const keptIds = new Set(kept.map((node) => String(node.id)));

    return {
        nodes: kept,
        edges: allEdges.filter(
            (edge) =>
                keptIds.has(String(edge.source)) &&
                keptIds.has(String(edge.target)),
        ),
    };
}
