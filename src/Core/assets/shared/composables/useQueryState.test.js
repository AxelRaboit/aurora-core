import { describe, it, expect, beforeEach } from "vitest";
import { useQueryState } from "./useQueryState.js";

function setUrl(url) {
    window.history.replaceState(null, "", url);
}

describe("useQueryState", () => {
    beforeEach(() => {
        setUrl("/list");
    });

    it("starts on the default when the URL says nothing", () => {
        expect(
            useQueryState("sort", { defaultValue: "name" }).value.value,
        ).toBe("name");
    });

    it("reads the value out of the URL", () => {
        setUrl("/list?sort=date");

        expect(
            useQueryState("sort", { defaultValue: "name" }).value.value,
        ).toBe("date");
    });

    it("writes the value into the URL", () => {
        useQueryState("sort", { defaultValue: "name" }).set("date");

        expect(window.location.search).toBe("?sort=date");
    });

    /**
     * Otherwise every list would grow ?sort=name&dir=asc&view=grid the moment
     * it opened, and a URL nobody chose is a URL nobody wants to copy.
     */
    it("keeps the default out of the URL", () => {
        setUrl("/list?sort=date");
        const { set } = useQueryState("sort", { defaultValue: "name" });

        set("name");

        expect(window.location.search).toBe("");
    });

    it("leaves the other parameters alone", () => {
        setUrl("/list?page=3&q=facture");

        useQueryState("sort", { defaultValue: "name" }).set("date");

        const params = new URLSearchParams(window.location.search);
        expect(params.get("page")).toBe("3");
        expect(params.get("q")).toBe("facture");
        expect(params.get("sort")).toBe("date");
    });

    it("keeps the path and the fragment intact", () => {
        setUrl("/backend/ged/documents#top");

        useQueryState("view", { defaultValue: "grid" }).set("list");

        expect(window.location.pathname).toBe("/backend/ged/documents");
        expect(window.location.hash).toBe("#top");
    });

    /** Back should leave the page, not undo a column click. */
    it("replaces the history entry rather than stacking one per change", () => {
        const before = window.history.length;
        const { set } = useQueryState("sort", { defaultValue: "name" });

        set("date");
        set("size");
        set("name");

        expect(window.history.length).toBe(before);
    });

    // ── Values that are not allowed ───────────────────────────────────────

    it("discards a value the URL has no business carrying", () => {
        setUrl("/list?view=carousel");

        expect(
            useQueryState("view", {
                defaultValue: "grid",
                valid: ["grid", "list"],
            }).value.value,
        ).toBe("grid");
    });

    it("refuses to write a value outside the allowed set", () => {
        const { value, set } = useQueryState("view", {
            defaultValue: "grid",
            valid: ["grid", "list"],
        });

        set("carousel");

        expect(value.value).toBe("grid");
        expect(window.location.search).toBe("");
    });

    it("accepts anything when no set is declared", () => {
        setUrl("/list?module=welding");

        expect(useQueryState("module").value.value).toBe("welding");
    });

    it("clears the parameter when set to an empty value", () => {
        setUrl("/list?module=welding");
        const { set } = useQueryState("module");

        set("");

        expect(window.location.search).toBe("");
    });
});
