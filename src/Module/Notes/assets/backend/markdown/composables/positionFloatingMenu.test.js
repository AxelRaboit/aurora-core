import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { positionFloatingMenu } from "@notes/backend/markdown/composables/positionFloatingMenu.js";

/**
 * The menu opened inside the editor's `overflow-auto` pane and got cut in half
 * whenever it flipped upward, because the old code flipped on "would this clip
 * the bottom of the viewport" and then never checked what it had flipped into.
 * These tests pin the two things that fix: coordinates in the viewport frame,
 * and a height that never exceeds the room on the chosen side.
 */
const MENU_HEIGHT = 256;

/**
 * jsdom gives every element a zero rect, so the geometry has to be faked. The
 * caret is placed by pinning the textarea's own rect; mirror and marker rects
 * are anchored at the same origin so the caret lands at the top of the box.
 */
function textareaAt({ top, height = 300 }) {
    const el = document.createElement("textarea");
    el.value = "";
    document.body.appendChild(el);

    el.getBoundingClientRect = () => ({
        top,
        left: 100,
        width: 400,
        height,
        bottom: top + height,
        right: 500,
    });
    return el;
}

beforeEach(() => {
    window.innerHeight = 800;
    window.innerWidth = 1280;
    // Mirror and marker share an origin: the caret sits on the first line.
    vi.spyOn(Element.prototype, "getBoundingClientRect").mockImplementation(
        function () {
            return {
                top: 0,
                left: 0,
                width: 0,
                height: 0,
                bottom: 0,
                right: 0,
            };
        },
    );
});

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = "";
});

describe("positionFloatingMenu", () => {
    it("places the menu below the caret when there is room", () => {
        const textarea = textareaAt({ top: 100 });

        const { top, maxHeight } = positionFloatingMenu(textarea, 0);

        expect(top).toBeGreaterThan(100);
        expect(maxHeight).toBe(MENU_HEIGHT);
    });

    it("never returns a position above the top of the viewport", () => {
        // A caret near the very top: flipping the menu upward used to put it at
        // a negative offset, which is what cropped it.
        const textarea = textareaAt({ top: 4 });

        const { top } = positionFloatingMenu(textarea, 0);

        expect(top).toBeGreaterThanOrEqual(0);
    });

    it("flips above the caret when below is too tight", () => {
        // 40px of room below, 700 above: the side with the room wins.
        const textarea = textareaAt({ top: 740 });

        const { top, maxHeight } = positionFloatingMenu(textarea, 0);

        expect(maxHeight).toBe(MENU_HEIGHT);
        expect(top + maxHeight).toBeLessThan(740);
    });

    it("shrinks the menu when neither side can hold it", () => {
        // A short window with the caret in the middle: no side fits 256px, so
        // the menu has to scroll rather than be cropped by the edge.
        window.innerHeight = 300;
        const textarea = textareaAt({ top: 150, height: 100 });

        const { top, maxHeight } = positionFloatingMenu(textarea, 0);

        expect(maxHeight).toBeLessThan(MENU_HEIGHT);
        expect(maxHeight).toBeGreaterThan(0);
        expect(top).toBeGreaterThanOrEqual(0);
        expect(top + maxHeight).toBeLessThanOrEqual(300);
    });

    it("keeps the menu inside the right edge", () => {
        const textarea = textareaAt({ top: 100 });
        window.innerWidth = 300;

        const { left } = positionFloatingMenu(textarea, 0);

        expect(left).toBeGreaterThanOrEqual(0);
        expect(left + 224).toBeLessThanOrEqual(300);
    });

    it("reports coordinates in the viewport frame, not the wrapper's", () => {
        // Two textareas at different scroll offsets must give different tops:
        // the old code returned wrapper-relative numbers, which were identical.
        const high = positionFloatingMenu(textareaAt({ top: 100 }), 0);
        const low = positionFloatingMenu(textareaAt({ top: 300 }), 0);

        expect(low.top).toBeGreaterThan(high.top);
    });
});
