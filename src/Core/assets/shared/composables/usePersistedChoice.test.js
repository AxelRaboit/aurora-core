import { describe, expect, it, beforeEach, vi } from "vitest";
import { nextTick } from "vue";
import { usePersistedChoice } from "./usePersistedChoice.js";

describe("usePersistedChoice", () => {
    beforeEach(() => {
        localStorage.clear();
        vi.restoreAllMocks();
    });

    it("opens on the fallback when nothing is stored", () => {
        const { choice } = usePersistedChoice("view", "board", [
            "board",
            "list",
        ]);

        expect(choice.value).toBe("board");
    });

    it("opens on what was stored", () => {
        localStorage.setItem("view", "list");

        const { choice } = usePersistedChoice("view", "board", [
            "board",
            "list",
        ]);

        expect(choice.value).toBe("list");
    });

    it("writes the choice back", async () => {
        const { choice } = usePersistedChoice("view", "board", [
            "board",
            "list",
        ]);

        choice.value = "list";
        await nextTick();

        expect(localStorage.getItem("view")).toBe("list");
    });

    // A view that was renamed or removed leaves readers on a value nothing
    // draws, which is a blank screen they cannot get out of without knowing
    // about localStorage.
    it("falls back when the stored value is no longer one of the choices", () => {
        localStorage.setItem("view", "gantt");

        const { choice } = usePersistedChoice("view", "board", [
            "board",
            "list",
        ]);

        expect(choice.value).toBe("board");
    });

    // A private window throws on every access rather than returning null.
    it("draws anyway when storage is unavailable", () => {
        vi.spyOn(Storage.prototype, "getItem").mockImplementation(() => {
            throw new Error("denied");
        });

        const { choice } = usePersistedChoice("view", "board", [
            "board",
            "list",
        ]);

        expect(choice.value).toBe("board");
    });
});
