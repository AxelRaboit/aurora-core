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
        const handle = wrapper.find('[data-handle="width"]');

        pointer(handle, "pointerdown");
        pointer(handle, "pointermove", 120);

        // A quarter of the way across 48 columns, on a zone starting at 0.
        expect(wrapper.emitted("resize").at(-1)).toEqual([0, 12]);
    });

    it("measures a width from the column its own zone starts on", () => {
        const wrapper = mountCanvas([zone("a", 24), zone("b", 24)]);
        const handle = wrapper.findAll('[data-handle="width"]')[1];

        pointer(handle, "pointerdown");
        pointer(handle, "pointermove", 360);

        // Column 36 under the pointer, less the 24 the second zone starts at.
        expect(wrapper.emitted("resize").at(-1)).toEqual([1, 12]);
    });

    // ── Dropping a zone in the empty part of the canvas ───────────────────

    /**
     * jsdom lays nothing out, so every box reports a zero rect. Giving each one
     * the rectangle its placement implies is what lets the drop maths be tested
     * at all — 480px across 48 columns, 80px tall rows.
     */
    function layOut(wrapper, rows) {
        wrapper.findAll("[data-zone]").forEach((item, index) => {
            const at = rows[index];

            item.element.getBoundingClientRect = () => ({
                left: at.column * 10,
                right: (at.column + at.span) * 10,
                width: at.span * 10,
                top: at.row * 100,
                bottom: at.row * 100 + 80,
                height: 80,
            });
        });
    }

    function dragTo(wrapper, from, clientX, clientY) {
        const boxes = wrapper.findAll(
            '.aurora-grid > div > button[draggable="true"]',
        );
        boxes[from].element.dispatchEvent(
            new MouseEvent("dragstart", { bubbles: true }),
        );

        const grid = wrapper.find(".aurora-grid").element;
        grid.dispatchEvent(
            new MouseEvent("drop", { clientX, clientY, bubbles: true }),
        );
    }

    it("moves a zone to the column it was dropped on", () => {
        const wrapper = mountCanvas([zone("a", 24), zone("b", 24)]);
        layOut(wrapper, [
            { row: 0, column: 0, span: 24 },
            { row: 0, column: 24, span: 24 },
        ]);

        // The second zone, dropped in the empty space below both — between
        // rows, so it asks for one of its own — a quarter of the way across.
        dragTo(wrapper, 1, 120, 250);

        expect(wrapper.emitted("move").at(-1)).toEqual([1, 1, 12, true]);
    });

    it("reads the place in the order from which boxes the drop is past", () => {
        const wrapper = mountCanvas([zone("a", 24), zone("b", 24)]);
        layOut(wrapper, [
            { row: 0, column: 0, span: 24 },
            { row: 0, column: 24, span: 24 },
        ]);

        // The second zone dropped over the first row, left of the first box's
        // midpoint: it goes in front of it, on that row.
        dragTo(wrapper, 1, 40, 40);

        expect(wrapper.emitted("move").at(-1)).toEqual([1, 0, 4, false]);
    });

    /**
     * A box means exchange, and the canvas behind it means move. Both would
     * fire on one drop without the stop, and hovering a zone's own box cancelled
     * the dragover through the grid then dropped to nothing — a cursor promising
     * a move that never came.
     */
    it("leaves a drop on another box to mean an exchange", () => {
        const wrapper = mountCanvas([zone("a", 24), zone("b", 24)]);
        const boxes = wrapper.findAll(
            '.aurora-grid > div > button[draggable="true"]',
        );

        boxes[0].element.dispatchEvent(
            new MouseEvent("dragstart", { bubbles: true }),
        );
        boxes[1].element.dispatchEvent(
            new MouseEvent("drop", { clientX: 300, bubbles: true }),
        );

        expect(wrapper.emitted("swap").at(-1)).toEqual([0, 1]);
        expect(wrapper.emitted("move")).toBeFalsy();
    });

    it("draws where the zone would land while it is being dragged", async () => {
        const wrapper = mountCanvas([zone("a", 48), zone("b", 24)]);
        layOut(wrapper, [
            { row: 0, column: 0, span: 48 },
            { row: 1, column: 0, span: 24 },
        ]);

        const boxes = wrapper.findAll(
            '.aurora-grid > div > button[draggable="true"]',
        );
        boxes[1].element.dispatchEvent(
            new MouseEvent("dragstart", { bubbles: true }),
        );

        wrapper.find(".aurora-grid").element.dispatchEvent(
            new MouseEvent("dragover", {
                clientX: 300,
                clientY: 250,
                bubbles: true,
            }),
        );
        await wrapper.vm.$nextTick();

        const ghost = wrapper.find("[data-ghost]");
        expect(ghost.exists()).toBe(true);
        // Column 31 is under the pointer, and a 24-wide zone cannot start
        // there without running off the row — so the ghost shows 25, which is
        // where the drop will actually put it. Showing the pointer's own column
        // would be promising a place the clamp is about to refuse.
        expect(ghost.attributes("style")).toContain("--start-base: 25");
    });

    // ── The left edge, which resizes like the right one ───────────────────

    it("turns a drag on the left edge into the column to start at", () => {
        const wrapper = mountCanvas([zone("a", 24)]);
        const handle = wrapper.find('[data-handle="start"]');

        pointer(handle, "pointerdown");
        pointer(handle, "pointermove", 240);

        // Halfway across, measured from the grid's left edge rather than from
        // the zone: a column counts from where the row begins.
        expect(wrapper.emitted("resizeStart").at(-1)).toEqual([0, 24]);
    });

    // The right edge is the one that stays put, so the two never move together
    // — and the floor and the width clamp live downstream, in one place.
    it("leaves the right edge to its own handle", () => {
        const wrapper = mountCanvas([zone("a", 24)]);
        const handle = wrapper.find('[data-handle="start"]');

        pointer(handle, "pointerdown");
        pointer(handle, "pointermove", 240);

        expect(wrapper.emitted("resize")).toBeFalsy();
    });

    it("draws the gap a pushed zone leaves in front of it", () => {
        const wrapper = mountCanvas([
            zone("a", 48),
            zone("b", 24, { offset: 24 }),
        ]);
        const items = wrapper.findAll("[data-zone]");

        // Rows on every other track, so the odd ones are free for the strips
        // that open a row between two. The page emits the walk's own numbers.
        expect(items[0].attributes("style")).toContain("--row-base: 2");
        expect(items[1].attributes("style")).toContain("--row-base: 4");
        expect(items[1].attributes("style")).toContain("--start-base: 25");
    });

    // The same case the composable pins, seen through the markup: the canvas
    // draws the break rather than leaving it to auto-placement, which would put
    // this zone beside its neighbour and show a layout the page will not.
    it("draws a break the columns beside the neighbour would have swallowed", () => {
        const wrapper = mountCanvas([
            zone("a", 32),
            zone("b", 16, { offset: 32, newRow: true }),
        ]);
        const items = wrapper.findAll("[data-zone]");

        expect(items[1].attributes("style")).toContain("--row-base: 4");
        expect(items[1].attributes("style")).toContain("--start-base: 33");
    });

    it("moves the left edge by the snap from the keyboard", async () => {
        const wrapper = mountCanvas([zone("a", 24, { offset: 12 })]);

        await wrapper
            .find('[data-handle="start"]')
            .trigger("keydown", { key: "ArrowRight" });

        expect(wrapper.emitted("resizeStart").at(-1)).toEqual([0, 16]);
    });

    it("takes the left edge as far left as it goes with Home", async () => {
        const wrapper = mountCanvas([zone("a", 24, { offset: 12 })]);

        await wrapper
            .find('[data-handle="start"]')
            .trigger("keydown", { key: "Home" });

        expect(wrapper.emitted("resizeStart").at(-1)).toEqual([0, 0]);
    });

    // ── Opening a row between two ─────────────────────────────────────────

    it("offers a strip above the first row and after every row", () => {
        const wrapper = mountCanvas([zone("a", 48), zone("b", 48)]);
        const strips = wrapper.findAll(".aurora-grid > button");

        // Above everything, then after each of the two rows.
        expect(strips).toHaveLength(3);
        expect(strips.map((s) => s.attributes("style"))).toEqual([
            expect.stringContaining("--row-base: 1"),
            expect.stringContaining("--row-base: 3"),
            expect.stringContaining("--row-base: 5"),
        ]);
    });

    it("says where in the order a zone added there would go", async () => {
        const wrapper = mountCanvas([zone("a", 48), zone("b", 48)]);
        const strips = wrapper.findAll(".aurora-grid > button");

        await strips[0].trigger("click");
        // Above everything: no row to break out of yet.
        expect(wrapper.emitted("addAt").at(-1)).toEqual([0, false]);

        await strips[1].trigger("click");
        expect(wrapper.emitted("addAt").at(-1)).toEqual([1, true]);
    });

    it("keeps the strips out of the drop maths", () => {
        const wrapper = mountCanvas([zone("a", 24), zone("b", 24)]);
        layOut(wrapper, [
            { row: 0, column: 0, span: 24 },
            { row: 0, column: 24, span: 24 },
        ]);

        // Counting a strip as a zone would put the drop a place further along.
        dragTo(wrapper, 1, 40, 40);

        expect(wrapper.emitted("move").at(-1)).toEqual([1, 0, 4, false]);
    });

    it("hands the width on unrounded, leaving the one clamp downstream", () => {
        const wrapper = mountCanvas([zone("a", 24)]);
        const handle = wrapper.find('[data-handle="width"]');

        pointer(handle, "pointerdown");
        pointer(handle, "pointermove", 137);

        expect(wrapper.emitted("resize").at(-1)[1]).toBeCloseTo(13.7);
    });

    it("ignores a move that belongs to another zone's handle", () => {
        const wrapper = mountCanvas([zone("a", 24), zone("b", 24)]);
        const handles = wrapper.findAll('[data-handle="width"]');

        pointer(handles[0], "pointerdown");
        pointer(handles[1], "pointermove", 360);

        expect(wrapper.emitted("resize")).toBeFalsy();
    });

    it("stops resizing once the pointer is let go", () => {
        const wrapper = mountCanvas([zone("a", 24)]);
        const handle = wrapper.find('[data-handle="width"]');

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
        const handle = wrapper.find('[data-handle="width"]');

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
            .find('[data-handle="width"]')
            .trigger("keydown", { key: "Tab" });

        expect(wrapper.emitted("resize")).toBeFalsy();
    });

    it("announces the width it carries, for a reader that cannot see it", () => {
        const wrapper = mountCanvas([zone("a", 24)]);
        const handle = wrapper.find('[data-handle="width"]');

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

        expect(wrapper.findAll('[data-handle="width"]')).toHaveLength(
            1,
            "one for the stack itself, none for what it holds",
        );
    });

    /**
     * Two intents need two targets. The stack's own box keeps the meaning every
     * zone has — exchange — and its slices say "inside, here". Aiming at a
     * slice is aiming at a rectangle that holds still, which is the same reason
     * dropping *between* zones was refused on the row axis.
     */
    it("moves a zone into a stack when it is dropped on one of its slices", () => {
        const wrapper = mountCanvas([
            zone("a", 24),
            stack("s", 24, [
                { type: "media", lg: 24 },
                { type: "media", lg: 24 },
            ]),
        ]);

        const slices = wrapper.findAll('.aurora-grid [style*="flex-grow"]');

        drag(boxes(wrapper)[0], "dragstart");
        drag(slices[1], "dragover");
        drag(slices[1], "drop");

        expect(wrapper.emitted("moveInto")[0]).toEqual([0, 1, 1]);
        expect(wrapper.emitted("swap")).toBeFalsy();
    });

    /**
     * A stack cannot go inside a stack, so a slice refuses it — and the event
     * carries on up to the box, which accepts it as the exchange every drop
     * means by default. The author is not left with a gesture that does
     * nothing, and the highlight says which of the two happened: the box
     * lights up, the slice does not.
     */
    it("falls back to an exchange when a stack is dropped on a stack's slice", () => {
        const wrapper = mountCanvas([
            stack("a", 24, [{ type: "media", lg: 48 }]),
            stack("b", 24, [{ type: "media", lg: 48 }]),
        ]);

        const slices = wrapper.findAll('.aurora-grid [style*="flex-grow"]');

        drag(boxes(wrapper)[0], "dragstart");
        drag(slices[1], "dragover");
        drag(slices[1], "drop");

        expect(wrapper.emitted("moveInto")).toBeFalsy();
        expect(wrapper.emitted("swap")[0]).toEqual(
            [0, 1],
            "the slice let it through and the box behind took it",
        );
    });

    it("takes a zone out of a stack when its slice is dropped on the row", () => {
        const wrapper = mountCanvas([
            zone("a", 24),
            stack("s", 24, [
                { type: "media", lg: 24 },
                { type: "media", lg: 24 },
            ]),
        ]);

        const slices = wrapper.findAll('.aurora-grid [style*="flex-grow"]');

        drag(slices[1], "dragstart");
        drag(boxes(wrapper)[0], "dragover");
        drag(boxes(wrapper)[0], "drop");

        expect(wrapper.emitted("moveOut")[0]).toEqual([1, 1, 0]);
        expect(wrapper.emitted("swap")).toBeFalsy();
    });

    /**
     * A slice dragged out is not the stack being dragged: without stopping the
     * event the box behind would start a drag of the whole stack, and the two
     * gestures would fight over the same pointer.
     */
    it("does not start a drag of the whole stack when a slice is picked up", () => {
        const wrapper = mountCanvas([
            zone("a", 24),
            stack("s", 24, [{ type: "media", lg: 48 }]),
        ]);

        drag(
            wrapper.findAll('.aurora-grid [style*="flex-grow"]')[0],
            "dragstart",
        );
        drag(boxes(wrapper)[0], "dragover");
        drag(boxes(wrapper)[0], "drop");

        expect(wrapper.emitted("moveOut")).toBeTruthy();
        expect(wrapper.emitted("swap")).toBeFalsy();
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
