import { describe, expect, it } from "vitest";
import { withQuery } from "./withQuery.js";

describe("withQuery", () => {
    it("starts a query on a bare path", () => {
        expect(withQuery("/suite/notes/markdown/search", { q: "brief" })).toBe(
            "/suite/notes/markdown/search?q=brief",
        );
    });

    // The hosted notes screen: every path already names its host space.
    it("joins with an ampersand when the path already carries a query", () => {
        expect(
            withQuery(
                "/suite/notes/markdown/search?notesHost=studio.customer_space%3A12",
                { q: "brief" },
            ),
        ).toBe(
            "/suite/notes/markdown/search?notesHost=studio.customer_space%3A12&q=brief",
        );
    });

    it("encodes values and leaves out empty ones", () => {
        expect(
            withQuery("/export", {
                folderId: 3,
                q: "a & b",
                spaceId: null,
                sort: "",
            }),
        ).toBe("/export?folderId=3&q=a+%26+b");
    });

    it("keeps the path as it is when there is nothing to add", () => {
        expect(withQuery("/export?x=1", {})).toBe("/export?x=1");
    });

    it("keeps a fragment at the end", () => {
        expect(withQuery("/notes#top", { q: "x" })).toBe("/notes?q=x#top");
    });
});
