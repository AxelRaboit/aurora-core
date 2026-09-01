import { afterEach, describe, expect, it, vi } from "vitest";
import {
    askPage,
    onPageNotice,
    onPanelRequest,
    tellPanels,
} from "./modulePanelBridge.js";

const stops = [];
const listen = (name, handler) => stops.push(onPanelRequest(name, handler));

afterEach(() => {
    while (stops.length) stops.pop()();
});

describe("the panel-to-page bridge", () => {
    it("hands the page what the panel sent", () => {
        const handler = vi.fn();
        listen("ged:select", handler);

        expect(askPage("ged:select", { folderId: 7 })).toBe(true);
        expect(handler).toHaveBeenCalledWith({ folderId: 7 });
    });

    /**
     * The case the design exists for: the panel is mounted on every page of
     * the module, and only one of them has a listing to filter. Elsewhere the
     * ask must fail so the row's href navigates instead.
     */
    it("says so when no page is listening", () => {
        expect(askPage("ged:select", { folderId: 7 })).toBe(false);
    });

    it("keeps two modules out of each other's way", () => {
        const ged = vi.fn();
        listen("ged:select", ged);

        expect(askPage("notes:select", { id: 1 })).toBe(false);
        expect(ged).not.toHaveBeenCalled();
    });

    it("stops listening when the page says so", () => {
        const handler = vi.fn();
        const stop = onPanelRequest("ged:select", handler);

        stop();

        expect(askPage("ged:select")).toBe(false);
        expect(handler).not.toHaveBeenCalled();
    });
});

describe("what the page announces", () => {
    it("reaches a panel that is listening", () => {
        const handler = vi.fn();
        stops.push(onPageNotice("notes:changed", handler));

        tellPanels("notes:changed", { notes: [1, 2] });

        expect(handler).toHaveBeenCalledWith({ notes: [1, 2] });
    });

    /**
     * An announcement is a statement of fact, not a question: unlike `askPage`
     * there is nothing to decline, and nobody listening is not a failure.
     */
    it("does not care whether anybody heard", () => {
        expect(() => tellPanels("notes:changed", {})).not.toThrow();
    });

    it("stops when the panel says so", () => {
        const handler = vi.fn();
        const stop = onPageNotice("notes:changed", handler);

        stop();
        tellPanels("notes:changed", {});

        expect(handler).not.toHaveBeenCalled();
    });
});
