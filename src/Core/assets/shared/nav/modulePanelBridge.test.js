import { afterEach, describe, expect, it, vi } from "vitest";
import { askPage, onPanelRequest } from "./modulePanelBridge.js";

const stops = [];
const listen = (name, handler) => stops.push(onPanelRequest(name, handler));

afterEach(() => {
    while (stops.length) stops.pop()();
});

describe("the panel-to-page bridge", () => {
    it("hands the page what the panel sent", () => {
        const handler = vi.fn();
        listen("ged:folder", handler);

        expect(askPage("ged:folder", { folderId: 7 })).toBe(true);
        expect(handler).toHaveBeenCalledWith({ folderId: 7 });
    });

    /**
     * The case the design exists for: the panel is mounted on every page of
     * the module, and only one of them has a listing to filter. Elsewhere the
     * ask must fail so the row's href navigates instead.
     */
    it("says so when no page is listening", () => {
        expect(askPage("ged:folder", { folderId: 7 })).toBe(false);
    });

    it("keeps two modules out of each other's way", () => {
        const ged = vi.fn();
        listen("ged:folder", ged);

        expect(askPage("notes:note", { id: 1 })).toBe(false);
        expect(ged).not.toHaveBeenCalled();
    });

    it("stops listening when the page says so", () => {
        const handler = vi.fn();
        const stop = onPanelRequest("ged:folder", handler);

        stop();

        expect(askPage("ged:folder")).toBe(false);
        expect(handler).not.toHaveBeenCalled();
    });
});
