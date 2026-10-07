import { describe, it, expect } from "vitest";
import { SECTION_PATTERNS } from "./gridSectionPatterns.js";

const t = (key) => key;
const build = (name) =>
    SECTION_PATTERNS.find((one) => one.key === name).build(t);

describe("the picture and text sections", () => {
    /**
     * The widths and columns the service pages settled on: inserted one after
     * the other, the two alternate without any setting to touch.
     */
    it("lays the picture left then right, on one row each", () => {
        const left = build("picture_text").zones;
        expect(
            left.map((zone) => [
                zone.type,
                zone.span.lg,
                zone.offset ?? 0,
                Boolean(zone.newRow),
            ]),
        ).toEqual([
            ["media", 20, 0, true],
            ["text", 26, 22, false],
        ]);

        const right = build("text_picture").zones;
        expect(
            right.map((zone) => [
                zone.type,
                zone.span.lg,
                zone.offset ?? 0,
                Boolean(zone.newRow),
            ]),
        ).toEqual([
            ["text", 26, 0, true],
            ["media", 20, 28, false],
        ]);
    });

    it("leaves a heading and a paragraph to write beside the picture", () => {
        const { zones, content } = build("picture_text");
        const text = zones.find((zone) => "text" === zone.type);

        expect(content[text.id].blocks.map((block) => block.type)).toEqual([
            "header",
            "paragraph",
        ]);
    });
});
