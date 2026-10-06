import { describe, expect, it } from "vitest";
import {
    boundsOf,
    cloneElements,
    colour,
    createElement,
    embedPreview,
    filters,
    mask,
    paint,
    RATIO,
    restack,
    shadow,
    unionOf,
    withGroups,
} from "./model.js";
import { snapAngle, snapEdges, snapMove } from "./snapping.js";
import { revealableIn } from "../reveals.js";

const box = (id, overrides = {}) => ({
    id,
    type: "shape",
    x: 10,
    y: 10,
    w: 20,
    h: 20,
    ...overrides,
});

describe("free model", () => {
    /** The deck's colours by name, so an element follows a retuned palette. */
    it("paints with the deck's own colours by name", () => {
        expect(colour("accent")).toBe("var(--slide-accent)");
        expect(colour("#112233")).toBe("#112233");
        expect(paint({ type: "solid", color: "ink" })).toBe("var(--slide-ink)");
        expect(
            paint({
                type: "linear",
                angle: 90,
                stops: [
                    { color: "#000000", at: 0 },
                    { color: "accent", at: 100 },
                ],
            }),
        ).toBe("linear-gradient(90deg, #000000 0%, var(--slide-accent) 100%)");
        expect(
            paint({
                type: "radial",
                stops: [
                    { color: "#ffffff", at: 0 },
                    { color: "#000000", at: 80 },
                ],
            }),
        ).toContain("radial-gradient");
        expect(paint(null)).toBeNull();
    });

    it("turns lengths into shares of the slide's width", () => {
        expect(shadow({ x: 0, y: 10, blur: 20, color: "#00000080" })).toBe(
            "drop-shadow(0cqw 1cqw 2cqw #00000080)",
        );
        expect(filters({ contrast: 140, blur: 10 })).toBe(
            "contrast(140%) blur(1cqw)",
        );
        expect(filters(null)).toBeNull();
        expect(mask("circle")).toContain("ellipse");
        expect(mask("none")).toBeNull();
    });

    /** A square on the wall is taller in per cent of a height than of a width. */
    it("creates a shape that is square on the wall, centred", () => {
        const shape = createElement("shape");

        expect(shape.h).toBeCloseTo(shape.w / RATIO);
        expect(shape.x + shape.w / 2).toBeCloseTo(50);
        expect(shape.y + shape.h / 2).toBeCloseTo(50);
    });

    it("measures a turned box in a square space", () => {
        const flat = boundsOf(box("a", { x: 0, y: 0, w: 40, h: 10 }));
        const turned = boundsOf(
            box("a", { x: 0, y: 0, w: 40, h: 10, rotate: 90 }),
        );

        expect(flat.right - flat.left).toBeCloseTo(40);
        // Turned a quarter, the width becomes the height: 40 per cent of the
        // width is 40 / RATIO per cent of the height.
        expect(turned.bottom - turned.top).toBeCloseTo(40 / RATIO);
        expect(turned.centreX).toBeCloseTo(flat.centreX);
    });

    it("selects a group whole", () => {
        const elements = [
            box("a", { group: "g" }),
            box("b", { group: "g" }),
            box("c"),
        ];

        expect(withGroups(elements, ["a"]).sort()).toEqual(["a", "b"]);
        expect(withGroups(elements, ["c"])).toEqual(["c"]);
    });

    it("moves elements one step or all the way, keeping the rest in order", () => {
        const elements = ["a", "b", "c", "d"].map((id) => box(id));
        const ids = (list) => list.map((element) => element.id).join("");

        expect(ids(restack(elements, ["b"], "forward"))).toBe("acbd");
        expect(ids(restack(elements, ["c"], "backward"))).toBe("acbd");
        expect(ids(restack(elements, ["a", "b"], "front"))).toBe("cdab");
        expect(ids(restack(elements, ["d"], "back"))).toBe("dabc");
        // Already at the front, nothing to step past.
        expect(ids(restack(elements, ["d"], "forward"))).toBe("abcd");
    });

    it("copies with fresh ids, groups renamed together, unlocked", () => {
        const copies = cloneElements([
            box("a", { group: "g", locked: true }),
            box("b", { group: "g" }),
        ]);

        expect(copies.map((element) => element.id)).not.toContain("a");
        expect(copies[0].group).toBe(copies[1].group);
        expect(copies[0].group).not.toBe("g");
        expect(copies[0].locked).toBeUndefined();
        expect(copies[0].x).toBe(12);
    });

    it("previews a YouTube link with its published still", () => {
        const preview = embedPreview("https://youtu.be/dQw4w9WgXcQ");

        expect(preview.embedUrl).toBe(
            "https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ",
        );
        expect(preview.thumbnail).toContain("dQw4w9WgXcQ");
        expect(embedPreview("https://vimeo.com/123456789").embedUrl).toBe(
            "https://player.vimeo.com/video/123456789",
        );
        expect(embedPreview("https://example.com").embedUrl).toBeNull();
    });

    it("draws the box around several elements", () => {
        const union = unionOf([
            box("a", { x: 0, y: 0, w: 10, h: 10 }),
            box("b", { x: 50, y: 40, w: 10, h: 10 }),
        ]);

        expect(union.left).toBe(0);
        expect(union.right).toBe(60);
        expect(union.bottom).toBe(50);
    });
});

describe("snapping", () => {
    it("catches the middle of the slide and the edge of another element", () => {
        const moving = boundsOf(box("m", { x: 39.6, y: 70, w: 20, h: 10 }));
        const other = boundsOf(box("o", { x: 0, y: 30, w: 30, h: 10 }));

        const snapped = snapMove(moving, [other]);

        // Its centre was at 49.6: caught on the middle line.
        expect(snapped.dx).toBeCloseTo(0.4);
        expect(snapped.guides).toContainEqual({ axis: "x", at: 50 });
    });

    it("leaves a box alone when nothing is within reach", () => {
        const snapped = snapMove(
            boundsOf(box("m", { x: 13, y: 13, w: 11, h: 11 })),
            [],
        );

        expect(snapped).toEqual({ dx: 0, dy: 0, guides: [] });
    });

    it("catches only the edges a handle drags", () => {
        const { edges } = snapEdges({ right: 99.5 }, []);

        expect(edges.right).toBe(100);
        expect(edges.left).toBeUndefined();
    });

    it("catches quarter turns and, with Shift, every fifteen degrees", () => {
        expect(snapAngle(88)).toBe(90);
        expect(snapAngle(80)).toBe(80);
        expect(snapAngle(23, true)).toBe(30);
        expect(snapAngle(-170)).toBe(-170);
        expect(snapAngle(-181)).toBe(180);
    });
});

describe("reveals on a free slide", () => {
    it("asks for as many presses as its latest element", () => {
        expect(
            revealableIn({
                layout: "free",
                content: { elements: [{ reveal: 2 }, {}, { reveal: 1 }] },
            }),
        ).toBe(2);
        expect(
            revealableIn({ layout: "free", content: { elements: [] } }),
        ).toBe(0);
    });
});
