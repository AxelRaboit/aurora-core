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

    /**
     * This renderer feeds the revisions overlay and the conflict merge view,
     * so a block it cannot draw comes out blank there — the reader sees an
     * empty row where a change might be hiding. postsList used to be exactly
     * that: handled server-side, missing here.
     */
    describe("postsList", () => {
        it("shows the configuration rather than nothing", () => {
            const html = renderBlocks([
                {
                    type: "postsList",
                    data: {
                        mode: "auto",
                        postTypeSlug: "article",
                        columns: 3,
                        perPage: 12,
                        title: "Derniers articles",
                    },
                },
            ]);

            expect(html).toContain("Derniers articles");
            expect(html).toContain("article");
            expect(html).toContain("3 col.");
            expect(html).toContain("12/page");
        });

        it("counts the posts a manual list pins", () => {
            const html = renderBlocks([
                {
                    type: "postsList",
                    data: {
                        mode: "manual",
                        postTypeSlug: "chambre",
                        columns: 2,
                        postIds: [4, 7, 9],
                    },
                },
            ]);

            expect(html).toContain("3 posts");
            expect(html).not.toContain("/page");
        });

        it("escapes what an author typed", () => {
            const html = renderBlocks([
                {
                    type: "postsList",
                    data: { mode: "auto", title: '<img onerror="x">' },
                },
            ]);

            expect(html).not.toContain("<img");
            expect(html).toContain("&lt;img");
        });
    });
});
