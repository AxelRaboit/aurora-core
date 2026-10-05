import { describe, it, expect, vi, afterEach } from "vitest";
import { useSidemenuDescriptions } from "./useSidemenuDescriptions.js";

afterEach(() => {
    vi.unstubAllGlobals();
});

describe("the menu's descriptions switch", () => {
    // Seeded from the server rather than fetched, so the menu draws its final
    // shape on first paint instead of changing once a script has run.
    it("starts where the server left it", () => {
        expect(useSidemenuDescriptions("", true).showDescriptions.value).toBe(
            true,
        );
        expect(useSidemenuDescriptions("", false).showDescriptions.value).toBe(
            false,
        );
    });

    // On unless the user turned it off: a description nobody knows exists is a
    // tooltip nobody hovers.
    it("is on when nothing says otherwise", () => {
        expect(useSidemenuDescriptions().showDescriptions.value).toBe(true);
    });

    it("flips both ways with one gesture", () => {
        const { showDescriptions, toggleDescriptions } =
            useSidemenuDescriptions();

        toggleDescriptions();
        expect(showDescriptions.value).toBe(false);

        toggleDescriptions();
        expect(showDescriptions.value).toBe(true);
    });

    // The switch moves first and the request is not awaited: waiting for a
    // round-trip before moving reads as a broken control.
    it("saves the new value without waiting for it", () => {
        const fetchMock = vi.fn(() => Promise.resolve({ ok: true }));
        vi.stubGlobal("fetch", fetchMock);

        const { showDescriptions, toggleDescriptions } =
            useSidemenuDescriptions("/prefs/descriptions");

        toggleDescriptions();

        expect(showDescriptions.value).toBe(false);
        expect(fetchMock).toHaveBeenCalledOnce();
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe("/prefs/descriptions");
        expect(JSON.parse(init.body)).toEqual({ show: false });
    });

    // Nothing to save against, so nothing is sent - which is what keeps the
    // composable usable in a test or a preview that has no route.
    it("sends nothing when it was given no route", () => {
        const fetchMock = vi.fn();
        vi.stubGlobal("fetch", fetchMock);

        useSidemenuDescriptions().toggleDescriptions();

        expect(fetchMock).not.toHaveBeenCalled();
    });
});
