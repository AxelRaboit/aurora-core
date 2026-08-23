import { describe, it, expect, vi, afterEach } from "vitest";
import {
    seoCounterClass,
    seoFieldClass,
    seoPixelWidth,
    SERP_PIXEL_LIMITS,
} from "./seoCounter.js";

describe("seoCounterClass", () => {
    it("returns text-muted when length is 0", () => {
        expect(seoCounterClass(0, 60)).toBe("text-muted");
    });

    it("returns text-green-500 when length is well below max", () => {
        expect(seoCounterClass(30, 60)).toBe("text-green-500");
    });

    it("returns text-amber-500 when length is between 85% and 100% of max", () => {
        expect(seoCounterClass(55, 60)).toBe("text-amber-500");
    });

    it("returns text-red-500 when length exceeds max", () => {
        expect(seoCounterClass(70, 60)).toBe("text-red-500");
    });

    it("returns text-amber-500 exactly at the max", () => {
        expect(seoCounterClass(60, 60)).toBe("text-amber-500");
    });
});

describe("seoFieldClass", () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it("falls back to counting characters where nothing can measure text", () => {
        // jsdom has no canvas backend, so this is the real path here - and the
        // one that runs server-side too.
        expect(seoPixelWidth("anything", "title")).toBeNull();
        expect(seoFieldClass("x".repeat(30), "title", 60)).toBe(
            "text-green-500",
        );
        expect(seoFieldClass("x".repeat(70), "title", 60)).toBe("text-red-500");
    });

    it("judges on measured width, not character count, when it can measure", async () => {
        vi.resetModules();
        vi.spyOn(document, "createElement").mockReturnValue({
            getContext: () => ({
                font: "",
                measureText: (text) => ({ width: text.length * 10 }),
            }),
        });

        const { seoFieldClass: measured } = await import("./seoCounter.js");

        // 100 characters sits comfortably under the 160-character mark, yet
        // 1000px overruns the description's 920px budget - the disagreement
        // that makes measuring worth doing.
        expect(seoCounterClass(100, 160)).toBe("text-green-500");
        expect(measured("x".repeat(100), "description", 160)).toBe(
            "text-red-500",
        );

        // And the converse: comfortably inside the real budget.
        expect(measured("x".repeat(60), "description", 160)).toBe(
            "text-green-500",
        );
        expect(SERP_PIXEL_LIMITS.description).toBe(920);
    });

    it("treats an empty value as untouched", () => {
        expect(seoFieldClass("", "description", 160)).toBe("text-muted");
        expect(seoFieldClass(null, "description", 160)).toBe("text-muted");
    });
});
