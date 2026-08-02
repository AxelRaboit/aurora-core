import { describe, it, expect } from "vitest";
import { renderBlocks } from "./blocksRenderer.js";

describe("renderBlocks", () => {
    it("renders nothing for an empty or invalid input", () => {
        expect(renderBlocks([])).toBe("");
        expect(renderBlocks(null)).toBe("");
    });

    it("drops a block type it does not know", () => {
        expect(renderBlocks([{ type: "productGrid", data: {} }])).toBe("");
    });
});
