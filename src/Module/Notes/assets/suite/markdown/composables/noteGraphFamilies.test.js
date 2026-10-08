import { describe, expect, it } from "vitest";
import {
    DEFAULT_NODE_COLOR,
    filterGraphBySpace,
    nodeColor,
} from "./noteGraphFamilies.js";

/**
 * The graph shown one space at a time, and its colours.
 *
 * What would break silently: a link towards a note of another space still
 * drawn (a line to nowhere), or an invalid colour handed to the canvas, which
 * ignores it and paints the node with the previous one.
 */
const NODES = [
    { id: 1, title: "Brief", spaceId: 10, color: "#ff0000" },
    { id: 2, title: "Compte rendu", spaceId: 10, color: null },
    { id: 3, title: "Journal", spaceId: 20, color: "#00aa00" },
];

const EDGES = [
    { source: 1, target: 2 },
    { source: 3, target: 1 },
    { source: 2, target: 3 },
];

describe("filterGraphBySpace", () => {
    it("keeps everything without a space", () => {
        for (const spaceId of ["", null, undefined]) {
            const graph = filterGraphBySpace(NODES, EDGES, spaceId);
            expect(graph.nodes).toHaveLength(3);
            expect(graph.edges).toHaveLength(3);
        }
    });

    it("keeps one space's notes and the links between them only", () => {
        const graph = filterGraphBySpace(NODES, EDGES, 10);

        expect(graph.nodes.map((node) => node.id)).toEqual([1, 2]);
        expect(graph.edges).toEqual([{ source: 1, target: 2 }]);
    });

    it("reads the id the select sends, a string", () => {
        const graph = filterGraphBySpace(NODES, EDGES, "20");

        expect(graph.nodes.map((node) => node.id)).toEqual([3]);
        expect(graph.edges).toEqual([]);
    });

    it("returns the same node objects, so their positions are kept", () => {
        const graph = filterGraphBySpace(NODES, EDGES, 10);

        expect(graph.nodes[0]).toBe(NODES[0]);
    });

    it("survives an older payload without spaces", () => {
        expect(filterGraphBySpace(undefined, undefined, 10)).toEqual({
            nodes: [],
            edges: [],
        });
    });
});

describe("nodeColor", () => {
    it("uses the family's colour", () => {
        expect(nodeColor(NODES[0])).toBe("#ff0000");
    });

    it("falls back to the default without a colour or with an invalid one", () => {
        expect(nodeColor(NODES[1])).toBe(DEFAULT_NODE_COLOR);
        expect(nodeColor({ color: "red" })).toBe(DEFAULT_NODE_COLOR);
        expect(nodeColor({ color: "#fff" })).toBe(DEFAULT_NODE_COLOR);
        expect(nodeColor(undefined)).toBe(DEFAULT_NODE_COLOR);
    });
});
