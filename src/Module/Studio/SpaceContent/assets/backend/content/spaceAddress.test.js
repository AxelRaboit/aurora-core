import { describe, expect, it } from "vitest";
import { viewFromAddress } from "./spaceAddress.js";

const VIEWS = ["content", "calendar", "files", "chat", "notes"];

describe("viewFromAddress", () => {
    it("opens the view the address names, content included", () => {
        expect(viewFromAddress("?view=calendar&item=4", VIEWS)).toBe(
            "calendar",
        );
        expect(viewFromAddress("?view=content", VIEWS)).toBe("content");
    });

    it("opens the content for a card or a state with no view", () => {
        expect(viewFromAddress("?item=42", VIEWS)).toBe("content");
        expect(viewFromAddress("?state=late_review", VIEWS)).toBe("content");
    });

    it("ignores a view this space does not offer", () => {
        expect(viewFromAddress("?view=drive", VIEWS)).toBeNull();
        expect(viewFromAddress("?view=drive&item=3", VIEWS)).toBe("content");
    });

    it("leaves the remembered view alone when the address says nothing", () => {
        expect(viewFromAddress("", VIEWS)).toBeNull();
    });
});
