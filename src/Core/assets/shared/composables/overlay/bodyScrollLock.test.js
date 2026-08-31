import { afterEach, describe, expect, it } from "vitest";
import { lockBodyScroll, resetBodyScrollLock } from "./bodyScrollLock.js";

afterEach(resetBodyScrollLock);

describe("the body scroll lock", () => {
    it("freezes the page while something is up", () => {
        lockBodyScroll();

        expect(document.body.style.overflow).toBe("hidden");
    });

    /**
     * The bug that made this a counter: the shallower overlay closed and gave
     * the page back while a modal was still covering it.
     */
    it("keeps the page frozen until the last holder lets go", () => {
        const first = lockBodyScroll();
        const second = lockBodyScroll();

        first();
        expect(document.body.style.overflow).toBe("hidden");

        second();
        expect(document.body.style.overflow).toBe("");
    });

    /**
     * A modal can be unmounted while open - the branch it lived in stopped
     * rendering - and then close. Both paths release, and releasing twice must
     * not free somebody else's hold.
     */
    it("ignores a second release from the same holder", () => {
        const release = lockBodyScroll();
        const other = lockBodyScroll();

        release();
        release();
        expect(document.body.style.overflow).toBe("hidden");

        other();
        expect(document.body.style.overflow).toBe("");
    });

    it("gives back whatever the page had before", () => {
        document.body.style.overflow = "clip";

        const release = lockBodyScroll();
        release();

        expect(document.body.style.overflow).toBe("clip");
        document.body.style.overflow = "";
    });
});
