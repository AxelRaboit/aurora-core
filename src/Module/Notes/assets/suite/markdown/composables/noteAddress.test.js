import { describe, expect, it } from "vitest";
import { idFromAddress } from "./noteAddress.js";

const at = (pathname, search = "") => ({ pathname, search });

describe("idFromAddress", () => {
    it("reads the id from the Notes module's path", () => {
        expect(
            idFromAddress(
                "/suite/notes/markdown/__id__",
                at("/suite/notes/markdown/12"),
            ),
        ).toBe(12);
        expect(
            idFromAddress(
                "/suite/notes/markdown/folder/__id__",
                at("/suite/notes/markdown/folder/3"),
            ),
        ).toBe(3);
    });

    it("does not take a folder address for a note's", () => {
        expect(
            idFromAddress(
                "/suite/notes/markdown/__id__",
                at("/suite/notes/markdown/folder/3"),
            ),
        ).toBeNull();
        expect(
            idFromAddress(
                "/suite/notes/markdown/__id__",
                at("/suite/notes/markdown"),
            ),
        ).toBeNull();
    });

    // A client space's notes: the id is a query parameter of the space's page.
    it("reads the id from a hosted page's query", () => {
        const template = "/workspace/7?view=notes&note=__id__";

        expect(
            idFromAddress(template, at("/workspace/7", "?view=notes&note=42")),
        ).toBe(42);
        expect(
            idFromAddress(template, at("/workspace/7", "?note=42&view=notes")),
        ).toBe(42);
    });

    it("refuses another page, another view, or no id", () => {
        const template = "/workspace/7?view=notes&note=__id__";

        expect(
            idFromAddress(template, at("/workspace/8", "?view=notes&note=42")),
        ).toBeNull();
        expect(
            idFromAddress(template, at("/workspace/7", "?view=chat&note=42")),
        ).toBeNull();
        expect(
            idFromAddress(template, at("/workspace/7", "?view=notes")),
        ).toBeNull();
        expect(
            idFromAddress(template, at("/workspace/7", "?view=notes&folder=3")),
        ).toBeNull();
    });
});
