import { describe, it, expect, vi } from "vitest";
import { mount } from "@vue/test-utils";
import PostGridCanvas from "./PostGridCanvas.vue";

vi.mock("vue-i18n", () => ({
    useI18n: () => ({ t: (key) => key }),
}));

function zone(id, lg, overrides = {}) {
    return { id, type: "text", span: { base: 48, md: null, lg }, ...overrides };
}

/**
 * The canvas reads its own width off the DOM, and jsdom lays nothing out — so
 * the grid is given a rect and the assertions work in columns from there. 480px
 * across 48 columns is a round 10px a column, which keeps the arithmetic in the
 * tests readable.
 */
function mountCanvas(zones, props = {}) {
    const wrapper = mount(PostGridCanvas, {
        props: { zones, snap: 4, ...props },
    });

    const grid = wrapper.find(".aurora-grid");

    if (!grid.exists()) {
        return wrapper;
    }

    grid.element.getBoundingClientRect = () => ({
        left: 0,
        width: 480,
        top: 0,
        height: 100,
        right: 480,
        bottom: 100,
    });

    return wrapper;
}

/**
 * Pointer events go out by hand rather than through `trigger`, which builds its
 * event first and assigns the extras after — and `clientX` on a jsdom MouseEvent
 * has only a getter. Passing it to the constructor is the way it takes. The
 * type is what listeners are keyed on, so a MouseEvent named `pointermove`
 * reaches a `v-on:pointermove` perfectly well.
 */
function pointer(wrapper, type, clientX = 0) {
    wrapper.element.dispatchEvent(
        new MouseEvent(type, { clientX, bubbles: true }),
    );
}

describe("PostGridCanvas", () => {
    it("draws one box per zone at its large-screen width", () => {
        const wrapper = mountCanvas([zone("a", 24), zone("b", 16)]);
        const items = wrapper.findAll(".aurora-grid > div");

        expect(items).toHaveLength(2);
        // Into --span-base, not --span-lg: the real chain only applies the lg
        // span above a 1024px viewport, and the panel is often read narrower.
        expect(items[0].attributes("style")).toContain("--span-base: 24");
        expect(items[1].attributes("style")).toContain("--span-base: 16");
    });

    it("says so when there is nothing to draw", () => {
        const wrapper = mountCanvas([]);

        expect(wrapper.find(".aurora-grid").exists()).toBe(false);
        expect(wrapper.text()).toContain("backend.posts.grid.canvas_empty");
    });

    /**
     * The 48 tracks span the grid's content box, while a drag is measured
     * against `getBoundingClientRect()`, which is its border box. Padding on
     * that element offsets every column by it — invisible in the middle of the
     * canvas and a column or so out near an edge. jsdom lays nothing out, so
     * the invariant is pinned on the markup: whatever carries the padding, it
     * is not the element being measured.
     */
    it("measures the tracks, not a box with padding around them", () => {
        const grid = mountCanvas([zone("a", 24)]).find(".aurora-grid");

        expect(
            [...grid.element.classList].filter((c) => /^p[xytrbl]?-/.test(c)),
        ).toEqual([]);
    });

    it("offers the kinds of zone it was given, and asks for the one clicked", async () => {
        const wrapper = mountCanvas([], {
            typeOptions: [
                { value: "text", label: "Texte" },
                { value: "media", label: "Image" },
            ],
        });

        const buttons = wrapper.findAll("button");
        expect(buttons.map((b) => b.text())).toEqual(["Texte", "Image"]);

        await buttons[1].trigger("click");
        expect(wrapper.emitted("add")[0]).toEqual(["media"]);
    });

    /** Disabled rather than hidden: a control that vanishes reads as a bug. */
    it("keeps the add buttons visible but dead at the zone cap", async () => {
        const wrapper = mountCanvas([], {
            typeOptions: [{ value: "text", label: "Texte" }],
            canAdd: false,
        });

        const button = wrapper.find("button");
        expect(button.exists()).toBe(true);

        await button.trigger("click");
        expect(wrapper.emitted("add")).toBeFalsy();
    });

    it("selects a zone when its box is clicked", async () => {
        const wrapper = mountCanvas([zone("a", 24), zone("b", 24)]);

        await wrapper
            .findAll(".aurora-grid > div")[1]
            .find("button")
            .trigger("click");

        expect(wrapper.emitted("update:selectedIndex")[0]).toEqual([1]);
    });

    it("turns a drag into the width the pointer is asking for", () => {
        const wrapper = mountCanvas([zone("a", 24)]);
        const handle = wrapper.find('[role="slider"]');

        pointer(handle, "pointerdown");
        pointer(handle, "pointermove", 120);

        // A quarter of the way across 48 columns, on a zone starting at 0.
        expect(wrapper.emitted("resize").at(-1)).toEqual([0, 12]);
    });

    it("measures a width from the column its own zone starts on", () => {
        const wrapper = mountCanvas([zone("a", 24), zone("b", 24)]);
        const handle = wrapper.findAll('[role="slider"]')[1];

        pointer(handle, "pointerdown");
        pointer(handle, "pointermove", 360);

        // Column 36 under the pointer, less the 24 the second zone starts at.
        expect(wrapper.emitted("resize").at(-1)).toEqual([1, 12]);
    });

    it("hands the width on unrounded, leaving the one clamp downstream", () => {
        const wrapper = mountCanvas([zone("a", 24)]);
        const handle = wrapper.find('[role="slider"]');

        pointer(handle, "pointerdown");
        pointer(handle, "pointermove", 137);

        expect(wrapper.emitted("resize").at(-1)[1]).toBeCloseTo(13.7);
    });

    it("ignores a move that belongs to another zone's handle", () => {
        const wrapper = mountCanvas([zone("a", 24), zone("b", 24)]);
        const handles = wrapper.findAll('[role="slider"]');

        pointer(handles[0], "pointerdown");
        pointer(handles[1], "pointermove", 360);

        expect(wrapper.emitted("resize")).toBeFalsy();
    });

    it("stops resizing once the pointer is let go", () => {
        const wrapper = mountCanvas([zone("a", 24)]);
        const handle = wrapper.find('[role="slider"]');

        pointer(handle, "pointerdown");
        pointer(handle, "pointerup");
        pointer(handle, "pointermove", 360);

        expect(wrapper.emitted("resize")).toBeFalsy();
    });

    // ── Reordering by dropping one zone on another ────────────────────────

    /** vue-test-utils cannot build a DragEvent with a dataTransfer either. */
    function drag(wrapper, type) {
        const event = new Event(type, { bubbles: true, cancelable: true });
        event.dataTransfer = { setData() {}, effectAllowed: "" };
        wrapper.element.dispatchEvent(event);

        return event;
    }

    function boxes(wrapper) {
        return wrapper.findAll(".aurora-grid [aria-pressed]");
    }

    it("exchanges two zones when one is dropped on the other", async () => {
        const wrapper = mountCanvas([
            zone("a", 24),
            zone("b", 16),
            zone("c", 8),
        ]);
        const box = boxes(wrapper);

        drag(box[0], "dragstart");
        drag(box[2], "dragover");
        drag(box[2], "drop");
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted("swap")[0]).toEqual([0, 2]);
        // The zone the author was holding is now at the target, and it is the
        // one they were working on.
        expect(wrapper.emitted("update:selectedIndex").at(-1)).toEqual([2]);
    });

    it("does nothing when a zone is dropped on itself", () => {
        const wrapper = mountCanvas([zone("a", 24), zone("b", 24)]);
        const box = boxes(wrapper);

        drag(box[0], "dragstart");
        drag(box[0], "drop");

        expect(wrapper.emitted("swap")).toBeFalsy();
    });

    /**
     * A `dragover` that is not cancelled means "nothing may be dropped here",
     * so cancelling it is what makes a box a target at all. Without this the
     * drop event never fires and the whole gesture silently does nothing.
     */
    it("accepts the drop by cancelling the dragover", () => {
        const wrapper = mountCanvas([zone("a", 24), zone("b", 24)]);
        const box = boxes(wrapper);

        drag(box[0], "dragstart");

        expect(drag(box[1], "dragover").defaultPrevented).toBe(true);
        expect(drag(box[0], "dragover").defaultPrevented).toBe(
            false,
            "a zone is not a drop target for itself",
        );
    });

    it("resizes from the keyboard, by the snap in force", async () => {
        const wrapper = mountCanvas([zone("a", 24)], { snap: 2 });
        const handle = wrapper.find('[role="slider"]');

        await handle.trigger("keydown", { key: "ArrowRight" });
        expect(wrapper.emitted("resize").at(-1)).toEqual([0, 26]);

        await handle.trigger("keydown", { key: "ArrowLeft" });
        expect(wrapper.emitted("resize").at(-1)).toEqual([0, 22]);

        await handle.trigger("keydown", { key: "End" });
        expect(wrapper.emitted("resize").at(-1)).toEqual([0, 48]);

        await handle.trigger("keydown", { key: "Home" });
        expect(wrapper.emitted("resize").at(-1)).toEqual([0, 2]);
    });

    it("leaves keys it has no business with alone", async () => {
        const wrapper = mountCanvas([zone("a", 24)]);

        await wrapper
            .find('[role="slider"]')
            .trigger("keydown", { key: "Tab" });

        expect(wrapper.emitted("resize")).toBeFalsy();
    });

    it("announces the width it carries, for a reader that cannot see it", () => {
        const wrapper = mountCanvas([zone("a", 24)]);
        const handle = wrapper.find('[role="slider"]');

        expect(handle.attributes("aria-valuenow")).toBe("24");
        expect(handle.attributes("aria-valuemin")).toBe("4");
        expect(handle.attributes("aria-valuemax")).toBe("48");
    });

    /**
     * The picture is absolutely positioned, so it fills whichever ancestor is
     * positioned. If that is the grid item rather than the box, it fills the
     * item's padding box — gutters included — and spills a gutter either side
     * of its own border, making the zone read as wider than it is and its row
     * as tighter than the others. Nothing throws and no layout is wrong; it
     * just looks off, which is why this is pinned rather than left to the eye.
     */
    it("keeps a zone's picture inside that zone's own box", () => {
        const wrapper = mountCanvas([
            zone("a", 24, { type: "media", media: { url: "/img/x.jpg" } }),
        ]);

        const box = wrapper.find("img").element.parentElement;

        expect(box.tagName).toBe("BUTTON");
        expect(box.classList.contains("relative")).toBe(true);
    });

    // ── Stacks ────────────────────────────────────────────────────────────

    function stack(id, lg, children) {
        return {
            ...zone(id, lg, { type: "stack" }),
            children: children.map((child, i) =>
                zone(`${id}-${i}`, child.lg, { type: child.type }),
            ),
        };
    }

    /**
     * The same grow factors the page uses, so the picture is right rather than
     * merely suggestive: a stack whose zones stand 3/4 to 1/4 must look like it
     * here too, or the canvas stops being worth trusting.
     */
    it("draws a stack's zones at the shares it holds them at", () => {
        const wrapper = mountCanvas([
            stack("s", 24, [
                { type: "media", lg: 36 },
                { type: "text", lg: 12 },
            ]),
        ]);

        const slices = wrapper.findAll(
            '.aurora-grid [aria-pressed] [style*="flex-grow"]',
        );

        expect(slices).toHaveLength(2);
        expect(slices[0].attributes("style")).toContain("flex-grow: 36");
        expect(slices[1].attributes("style")).toContain("flex-grow: 12");
    });

    it("says how many zones a stack holds", () => {
        const wrapper = mountCanvas([
            stack("s", 24, [
                { type: "media", lg: 24 },
                { type: "media", lg: 24 },
            ]),
        ]);

        expect(wrapper.text()).toContain("24/48 · 2");
    });

    /**
     * A share is a height, and the canvas only ever resized widths. Offering a
     * handle on a slice would promise a gesture that has nowhere to go.
     */
    it("puts no resize handle on a stack's own zones", () => {
        const wrapper = mountCanvas([
            stack("s", 24, [
                { type: "media", lg: 24 },
                { type: "media", lg: 24 },
            ]),
        ]);

        expect(wrapper.findAll('[role="slider"]')).toHaveLength(
            1,
            "one for the stack itself, none for what it holds",
        );
    });

    it("shows a linked publication by name, and a picked image itself", () => {
        const wrapper = mountCanvas(
            [
                zone("a", 24, { type: "post", postId: 7 }),
                zone("b", 24, { type: "media", media: { url: "/img/x.jpg" } }),
            ],
            { postOptions: [{ id: 7, title: "Premiers pas" }] },
        );

        expect(wrapper.text()).toContain("Premiers pas");
        expect(wrapper.find("img").attributes("src")).toBe("/img/x.jpg");
    });
});
